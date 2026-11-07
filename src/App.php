<?php

declare(strict_types=1);

namespace App;

use App\Database\Backup;
use App\Database\Connection;
use App\Database\Migrator;
use App\Http\LazyCsrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Model\Visibility;
use App\Repository\ApiTokenRepository;
use App\Repository\CacheRepository;
use App\Repository\CheckinRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\SettingsRepository;
use App\Service\Auth;
use App\Service\CheckinService;
use App\Service\Exporter;
use App\Service\SwarmImporter;
use App\Service\UpdateCheck;
use App\Service\Osm\Geoapify;
use App\Service\Osm\HttpClient;
use App\Service\Osm\NearbySearch;
use App\Service\Osm\Overpass;
use App\Service\Osm\PlaceSearch;
use App\Service\Osm\Throttle;
use PDO;

/**
 * Composition root and request kernel. Services are created lazily on first use.
 * Add a getter here when a new feature needs a new repository or service.
 */
final class App
{
    public const NAME = 'svaerma';
    public const VERSION = '0.8.1';
    /** GitHub repository: footer link and update check. */
    public const REPOSITORY = 'tgigold/svaerma';
    public const DEFAULT_RADIUS = 150;
    public const DEFAULT_PUBLIC_DELAY = 2; // hours
    /** Total time for one nearby search incl. all fallbacks (seconds), below typical proxy timeouts. */
    public const NEARBY_BUDGET = 25;
    /** Nearby searches per API token and minute. */
    public const API_NEARBY_PER_MINUTE = 30;

    private ?PDO $pdo = null;
    private ?Session $session = null;
    private ?View $view = null;
    private ?Router $router = null;
    /** Token of the current API request (set in dispatchApi()). */
    private ?int $apiTokenId = null;

    /**
     * @param bool|null $installed whether setup is complete; defaults to "config/config.php exists"
     *                             (injectable for kernel tests)
     */
    public function __construct(
        public readonly Config $config,
        private ?bool $installed = null,
    ) {
    }

    private function installed(): bool
    {
        return $this->installed ??= Config::exists();
    }

    // ---- Services -------------------------------------------------------

    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::open((string) $this->config->get('db_path'));
    }

    public function dataDir(): string
    {
        return (string) $this->config->get('data_dir');
    }

    public function session(): Session
    {
        return $this->session ??= new Session($this->dataDir() . '/sessions', !$this->config->isDev());
    }

    public function view(): View
    {
        return $this->view ??= new View(APP_ROOT . '/templates');
    }

    public function router(): Router
    {
        if ($this->router === null) {
            $this->router = new Router();
            (require APP_ROOT . '/src/routes.php')($this->router);
        }
        return $this->router;
    }

    public function settings(): SettingsRepository
    {
        return new SettingsRepository($this->pdo());
    }

    public function auth(): Auth
    {
        return new Auth($this->session(), $this->settings(), new LoginAttemptRepository($this->pdo()));
    }

    public function backup(): Backup
    {
        return new Backup($this->pdo(), $this->dataDir() . '/backups');
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo(), APP_ROOT . '/migrations', $this->backup());
    }

    public function apiTokens(): ApiTokenRepository
    {
        return new ApiTokenRepository($this->pdo());
    }

    public function checkins(): CheckinRepository
    {
        return new CheckinRepository($this->pdo());
    }

    public function checkinService(): CheckinService
    {
        return new CheckinService($this->checkins(), (string) $this->config->get('timezone'));
    }

    public function swarmImporter(): SwarmImporter
    {
        return new SwarmImporter($this->checkins(), $this->checkinService(), (string) $this->config->get('timezone'));
    }

    public function exporter(): Exporter
    {
        return new Exporter($this->checkins(), rtrim((string) $this->config->get('base_url'), '/'));
    }

    /** Null when the check is switched off in config.php ('update_check' => false). */
    public function updateCheck(): ?UpdateCheck
    {
        if ($this->config->get('update_check', true) !== true) {
            return null;
        }
        return new UpdateCheck($this->http(5), new CacheRepository($this->pdo()), self::REPOSITORY, self::VERSION);
    }

    /** Search radius for nearby places in metres (user setting). */
    public function nearbyRadius(): int
    {
        return (int) ($this->settings()->get('nearby_radius') ?? self::DEFAULT_RADIUS);
    }

    /** Public mode: history, map and detail pages visible without login. */
    public function publicEnabled(): bool
    {
        return $this->settings()->get('public_enabled') === '1';
    }

    /** What visitors may see; only meaningful when publicEnabled(). */
    public function publicVisibility(): Visibility
    {
        $settings = $this->settings();
        $limit = (string) $settings->get('public_limit', '');
        return new Visibility(
            limit: $limit === '' ? null : max(1, (int) $limit),
            delayHours: (int) ($settings->get('public_delay_hours') ?? self::DEFAULT_PUBLIC_DELAY),
            showNotes: $settings->get('public_notes', '1') === '1',
            showManual: $settings->get('public_manual', '1') === '1',
        );
    }

    public function http(?int $timeout = null, ?float $deadline = null): HttpClient
    {
        // Fair use: identify the app and give a contact address.
        $ua = sprintf(
            self::NAME . '/%s (+%s; %s)',
            self::VERSION,
            (string) $this->config->get('base_url'),
            (string) $this->config->get('contact_email'),
        );
        return new HttpClient($ua, $timeout ?? (int) $this->config->get('osm.timeout'), $deadline);
    }

    public function overpass(?float $deadline = null): Overpass
    {
        return new Overpass(
            $this->http((int) $this->config->get('osm.overpass_timeout'), $deadline),
            new CacheRepository($this->pdo()),
            $this->overpassEndpoints(),
            (int) $this->config->get('osm.cache_ttl'),
        );
    }

    /** Geoapify is used only when an API key is set in the settings. */
    public function geoapify(?float $deadline = null): ?Geoapify
    {
        $key = trim((string) $this->settings()->get('geoapify_key', ''));
        if ($key === '') {
            return null;
        }
        return new Geoapify(
            $this->http(null, $deadline),
            new CacheRepository($this->pdo()),
            (string) $this->config->get('osm.geoapify_url'),
            $key,
            (int) $this->config->get('osm.cache_ttl'),
        );
    }

    public function nearbySearch(): NearbySearch
    {
        // One budget for the whole chain, so Overpass + fallbacks finish before
        // proxy/FPM timeouts or the iOS shortcut give up.
        $deadline = microtime(true) + self::NEARBY_BUDGET;
        $overpass = $this->overpass($deadline);
        $providers = ['overpass' => $overpass->nearby(...)];
        $geoapify = $this->geoapify($deadline);
        if ($geoapify !== null) {
            $providers['geoapify'] = $geoapify->nearby(...);
        }
        $providers['photon'] = $this->placeSearch(null, $deadline)->nearby(...);
        return new NearbySearch($providers, fn (string $msg) => $this->log($msg));
    }

    /** @return list<string> Overpass endpoints from config.php, in order */
    public function configuredOverpassEndpoints(): array
    {
        $fallbacks = $this->config->get('osm.overpass_fallback_urls', []);
        return self::urlList([(string) $this->config->get('osm.overpass_url'), ...(is_array($fallbacks) ? $fallbacks : [])]);
    }

    /**
     * All Overpass endpoints in the order they are tried: overpass.ch (if a key is set),
     * then config.php, then the extra URLs from the settings.
     *
     * @return list<array{url: string, label: string, headers?: array<string, string>}>
     */
    public function overpassEndpoints(): array
    {
        $endpoints = [];
        $key = trim((string) $this->settings()->get('overpass_ch_key', ''));
        if ($key !== '') {
            // Key in a header, never in the URL, so it cannot leak into logs or messages.
            $endpoints[] = ['url' => (string) $this->config->get('osm.overpass_ch_url'), 'label' => 'overpass.ch', 'headers' => ['X-API-Key' => $key]];
        }
        $extra = preg_split('/\s+/', (string) $this->settings()->get('overpass_extra_urls', '')) ?: [];
        foreach (self::urlList([...$this->configuredOverpassEndpoints(), ...$extra]) as $url) {
            $endpoints[] = ['url' => $url, 'label' => (string) (parse_url($url, PHP_URL_HOST) ?: $url)];
        }
        return $endpoints;
    }

    /**
     * @param array<mixed> $urls
     * @return list<string>
     */
    private static function urlList(array $urls): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $urls))));
    }

    /** @param int|null $cacheTtl 0 effectively bypasses the cache (diagnostics) */
    public function placeSearch(?int $cacheTtl = null, ?float $deadline = null): PlaceSearch
    {
        return new PlaceSearch(
            $this->http(null, $deadline),
            new CacheRepository($this->pdo()),
            (string) $this->config->get('osm.search_provider'),
            (string) $this->config->get('osm.photon_url'),
            (string) $this->config->get('osm.photon_reverse_url'),
            (string) $this->config->get('osm.nominatim_url'),
            new Throttle($this->dataDir() . '/nominatim.lock', 1.0),
            $cacheTtl ?? (int) $this->config->get('osm.cache_ttl'),
        );
    }

    // ---- Kernel ---------------------------------------------------------

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $this->log($e);
            $response = $this->error($request, 500, $this->config->isDev() ? (string) $e : null);
        }
        return $this->secure($response);
    }

    private function dispatch(Request $request): Response
    {
        if (!$this->config->isDev() && !$request->https) {
            // The configured host, not the Host header (which the client controls).
            $host = (string) parse_url((string) $this->config->get('base_url'), PHP_URL_HOST);
            if ($host === '') {
                // Before setup there is no base_url yet; accept only a plausible host name.
                $host = (string) $request->header('host');
                if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) {
                    return $this->error($request, 400);
                }
            }
            return Response::redirect('https://' . $host . $request->path, 301);
        }

        $match = $this->router()->match($request->method, $request->path);
        if ($match === null) {
            $allowed = $this->router()->allowedMethods($request->path);
            if ($allowed !== []) {
                return $this->error($request, 405)->withHeader('Allow', implode(', ', $allowed));
            }
            return $this->installed() ? $this->error($request, 404) : Response::redirect('/setup');
        }
        $route = $match['route'];

        // Setup routes only exist until config/config.php is written, everything else only after.
        if ($route->option('setup')) {
            if ($this->installed()) {
                return $this->error($request, 404);
            }
        } elseif (!$this->installed()) {
            return Response::redirect('/setup');
        }

        if ($route->option('api')) {
            return $this->dispatchApi($request, $route->handler, $match['params']);
        }

        // Started lazily: requests without a session cookie only get one when a page
        // actually needs it (a form with a CSRF token, a flash message, a login).
        $session = $this->session();

        if ($request->method === 'POST' && !$session->validCsrf($request->postValue('_csrf'))) {
            return $this->error($request, 400, null, 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es nochmal.');
        }

        if (!$route->option('public') && !$route->option('setup')) {
            $loggedIn = $this->auth()->check();
            // Routes marked "guest" are readable without login when public mode is on.
            if (!$loggedIn && $route->option('guest_redirect') && $this->publicEnabled()) {
                return Response::redirect((string) $route->option('guest_redirect'));
            }
            if (!$loggedIn && !($route->option('guest') && $this->publicEnabled())) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Nicht angemeldet.'], 401);
                }
                if ($request->method === 'GET') {
                    $session->set('intended', $request->path);
                }
                return Response::redirect('/login');
            }
            if (!$route->option('ignore_migrations') && $this->migrator()->pending() !== []) {
                if (!$loggedIn) {
                    return $this->error($request, 503, null, 'Wartungsarbeiten, bitte später wieder vorbeischauen.');
                }
                return $request->wantsJson()
                    ? Response::json(['error' => 'Datenbank-Migration ausstehend.'], 503)
                    : Response::redirect('/settings');
            }
        }

        $this->view()->share('app', $this);
        $this->view()->share('csrf', new LazyCsrf($session));
        $this->view()->share('currentPath', $request->path);
        $this->view()->share('loggedIn', !$route->option('setup') && $this->auth()->check());
        $this->view()->share('publicNav', !$route->option('setup') && $this->publicEnabled());
        $this->view()->share('geoapifyActive', !$route->option('setup') && $this->settings()->get('geoapify_key', '') !== '');
        $this->view()->share('overpassChActive', !$route->option('setup') && $this->settings()->get('overpass_ch_key', '') !== '');

        [$class, $method] = $route->handler;
        $controller = new $class($this, $request);
        return $controller->$method($match['params']);
    }

    /**
     * API requests: bearer token instead of session and CSRF, always JSON.
     *
     * @param array{class-string, string} $handler
     * @param array<string, string> $params
     */
    private function dispatchApi(Request $request, array $handler, array $params): Response
    {
        if ($this->migrator()->pending() !== []) {
            return Response::json(['error' => 'Datenbank-Migration ausstehend.'], 503);
        }

        // A valid token always works: failed attempts by others must not lock out the owner.
        // Failures have their own counter, separate from the login form.
        $header = (string) $request->header('authorization');
        $token = preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
        $this->apiTokenId = $token !== '' ? $this->apiTokens()->verify($token) : null;
        if ($this->apiTokenId === null) {
            $now = time();
            $attempts = new LoginAttemptRepository($this->pdo());
            $attempts->purgeOlderThan($now - Auth::WINDOW);
            if ($this->auth()->isLocked($request->ip, $now, LoginAttemptRepository::API)) {
                return Response::json(['error' => 'Zu viele Fehlversuche, bitte später erneut versuchen.'], 429);
            }
            $attempts->record($request->ip, $now, LoginAttemptRepository::API);
            return Response::json(['error' => 'Ungültiger oder fehlender API-Token.'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        [$class, $method] = $handler;
        $controller = new $class($this, $request);
        return $controller->$method($params);
    }

    public function apiTokenId(): ?int
    {
        return $this->apiTokenId;
    }

    /**
     * Fixed-window rate limit backed by the cache table.
     *
     * @return bool false when the limit for this window is used up
     */
    public function rateLimit(string $key, int $max, int $window = 60): bool
    {
        $cache = new CacheRepository($this->pdo());
        $slot = sprintf('ratelimit:%s:%d', $key, intdiv(time(), $window));
        $count = (int) ($cache->get($slot) ?? 0) + 1;
        $cache->set($slot, (string) $count, $window);
        return $count <= $max;
    }

    public function error(Request $request, int $status, ?string $debug = null, ?string $message = null): Response
    {
        $titles = [400 => 'Ungültige Anfrage', 403 => 'Kein Zugriff', 404 => 'Nicht gefunden', 405 => 'Methode nicht erlaubt', 429 => 'Zu viele Anfragen', 500 => 'Interner Fehler', 503 => 'Gleich wieder da'];
        $title = $titles[$status] ?? 'Fehler';
        if ($request->wantsJson()) {
            return Response::json(['error' => $message ?? $title], $status);
        }
        try {
            $html = $this->view()->render('errors/error', [
                'status' => $status,
                'title' => $title,
                'message' => $message,
                'debug' => $debug,
                'loggedIn' => false,
            ], 'layout_minimal');
        } catch (\Throwable) {
            $html = '<h1>' . e($title) . '</h1>';
        }
        return Response::html($html, $status);
    }

    private function secure(Response $response): Response
    {
        $tiles = parse_url((string) $this->config->get('osm.tile_url'), PHP_URL_HOST) ?: 'tile.openstreetmap.org';
        $csp = "default-src 'self'; "
            . "script-src 'self'; "
            . "style-src 'self'; "
            . "font-src 'self'; "
            . "img-src 'self' data: https://$tiles https://*.$tiles; "
            . "connect-src 'self'; "
            . "manifest-src 'self'; worker-src 'self'; "
            . "object-src 'none'; frame-src 'none'; "
            . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            // OSM tile servers require a Referer; send the origin only, never paths.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(self), camera=(), microphone=()',
            'Content-Security-Policy' => $csp,
        ];
        if (!$this->config->isDev()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $name => $value) {
            $response->headers[$name] ??= $value;
        }
        return $response;
    }

    public function log(\Throwable|string $error): void
    {
        $dir = $this->dataDir() . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf("[%s] %s\n", gmdate('Y-m-d H:i:s'), is_string($error) ? $error : (string) $error);
        @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX);
    }
}
