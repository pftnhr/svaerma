<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Http\IpRange;
use App\Http\Response;
use App\Service\Osm\Overpass;

/**
 * Settings and administration on one page (single-user tool).
 */
final class SettingsController extends Controller
{
    /** @param array<string, string> $params */
    public function index(array $params): Response
    {
        $settings = $this->app->settings();
        $migrator = $this->app->migrator();
        $dbFile = (string) $this->app->config->get('db_path');
        $key = (string) $settings->get('geoapify_key', '');

        return $this->render('settings/index', [
            'radius' => $this->app->nearbyRadius(),
            'minRadius' => Overpass::MIN_RADIUS,
            'maxRadius' => Overpass::MAX_RADIUS,
            'public' => [
                'enabled' => $this->app->publicEnabled(),
                'limit' => (string) $settings->get('public_limit', ''),
                'delay' => (int) ($settings->get('public_delay_hours') ?? App::DEFAULT_PUBLIC_DELAY),
                'notes' => $settings->get('public_notes', '1') === '1',
                'manual' => $settings->get('public_manual', '1') === '1',
            ],
            'geoapifyHint' => self::hint($key),
            'overpassChHint' => self::hint((string) $settings->get('overpass_ch_key', '')),
            'overpassExtra' => (string) $settings->get('overpass_extra_urls', ''),
            'overpassConfigured' => $this->app->configuredOverpassEndpoints(),
            // Migration 003 may still be pending right after an update.
            'tokens' => in_array('003_api_tokens', $migrator->applied(), true) ? $this->app->apiTokens()->all() : null,
            'newToken' => $this->takeNewToken(),
            'baseUrl' => rtrim((string) $this->app->config->get('base_url'), '/'),
            'uploadLimit' => (string) ini_get('upload_max_filesize'),
            'importReady' => in_array('004_venue_ref', $migrator->applied(), true),
            'version' => App::VERSION,
            'applied' => $migrator->applied(),
            'pending' => $migrator->pending(),
            'backups' => array_map(
                static fn (string $f): array => ['name' => basename($f), 'size' => (int) filesize($f)],
                $this->app->backup()->list(),
            ),
            'dbSize' => is_file($dbFile) ? (int) filesize($dbFile) : 0,
            'phpVersion' => PHP_VERSION,
            'sqliteVersion' => (string) $this->app->pdo()->query('SELECT sqlite_version()')->fetchColumn(),
        ]);
    }

    /** @param array<string, string> $params */
    public function save(array $params): Response
    {
        $in = $this->request;
        $errors = [];

        $radius = filter_var($in->input('nearby_radius'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => Overpass::MIN_RADIUS, 'max_range' => Overpass::MAX_RADIUS],
        ]);
        if ($radius === false) {
            $errors[] = sprintf('Der Umkreis muss zwischen %d und %d m liegen.', Overpass::MIN_RADIUS, Overpass::MAX_RADIUS);
        }

        $limit = $in->input('public_limit');
        if ($limit !== '' && filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors[] = 'Die Anzahl öffentlicher Check-ins muss eine Zahl ab 1 sein (leer = alle).';
        }

        $delay = filter_var($in->input('public_delay_hours', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8760]]);
        if ($delay === false) {
            $errors[] = 'Die Verzögerung muss zwischen 0 und 8760 Stunden liegen.';
        }

        $key = $in->input('geoapify_key');
        if ($key !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
            $errors[] = 'Der Geoapify-Key sieht ungültig aus.';
        }

        $overpassChKey = $in->input('overpass_ch_key');
        if ($overpassChKey !== '' && !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $overpassChKey)) {
            $errors[] = 'Der overpass.ch-Key sieht ungültig aus.';
        }

        $extra = $this->extraUrls($in->input('overpass_extra_urls'));
        foreach ($extra as $url) {
            if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[^\s]*)?$#i', $url)) {
                $errors[] = "Ungültiger Overpass-Endpunkt: $url (erwartet z. B. https://example.org/api/interpreter)";
            } elseif (!IpRange::hostIsPublic((string) parse_url($url, PHP_URL_HOST))) {
                // The server must not be pointed at its own network (localhost, LAN, cloud metadata).
                $errors[] = "Overpass-Endpunkt $url ist nicht erreichbar oder zeigt auf eine interne Adresse.";
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->flash('danger', $error);
            }
            return $this->redirect('/settings');
        }

        $settings = $this->app->settings();
        $settings->set('nearby_radius', (string) $radius);
        $settings->set('public_enabled', $in->input('public_enabled') === '1' ? '1' : '0');
        $settings->set('public_limit', $limit);
        $settings->set('public_delay_hours', (string) $delay);
        $settings->set('public_notes', $in->input('public_notes') === '1' ? '1' : '0');
        $settings->set('public_manual', $in->input('public_manual') === '1' ? '1' : '0');
        $settings->set('overpass_extra_urls', implode("\n", $extra));
        if ($in->input('overpass_ch_remove') === '1') {
            $settings->set('overpass_ch_key', '');
        } elseif ($overpassChKey !== '') {
            $settings->set('overpass_ch_key', $overpassChKey);
        }
        if ($in->input('geoapify_remove') === '1') {
            $settings->set('geoapify_key', '');
        } elseif ($key !== '') {
            $settings->set('geoapify_key', $key);
        }

        $this->flash('success', 'Einstellungen gespeichert.');
        return $this->redirect('/settings');
    }

    /** @return list<string> one URL per line, empty lines dropped */
    private function extraUrls(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
    }

    /** @param array<string, string> $params */
    public function createToken(array $params): Response
    {
        $name = mb_substr($this->request->input('name'), 0, 100);
        if ($name === '') {
            $this->flash('danger', 'Bitte gib dem Token einen Namen, z. B. „iPhone-Kurzbefehl“.');
            return $this->redirect('/settings#api');
        }
        // Shown exactly once on the next page view, never stored in plain text.
        $this->app->session()->set('new_token', $this->app->apiTokens()->create($name));
        return $this->redirect('/settings#api');
    }

    /** @param array<string, string> $params */
    public function revokeToken(array $params): Response
    {
        $this->app->apiTokens()->revoke((int) $params['id']);
        $this->flash('success', 'Token widerrufen.');
        return $this->redirect('/settings#api');
    }

    /** @param array<string, string> $params */
    public function logoutAll(array $params): Response
    {
        $this->app->auth()->logoutEverywhere();
        return $this->redirect('/login');
    }

    /** Last characters of a secret, enough to recognise it. */
    private static function hint(string $secret): ?string
    {
        return $secret !== '' ? '…' . substr($secret, -4) : null;
    }

    private function takeNewToken(): ?string
    {
        $token = $this->app->session()->get('new_token');
        $this->app->session()->remove('new_token');
        return is_string($token) ? $token : null;
    }
}
