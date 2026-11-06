<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Http\IpRange;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Model\Checkin;
use App\Repository\CheckinRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\SettingsRepository;
use App\Service\Auth;
use App\Service\CheckinService;
use App\Service\Osm\NearbySearch;
use App\Service\Osm\OsmException;
use App\Service\Osm\PlaceSearch;
use App\Service\Osm\Throttle;
use App\Service\Setup;
use App\Service\SwarmImporter;
use App\Repository\CacheRepository;
use App\Model\Place;

/**
 * Hardening: proxies, CSRF, sessions, lockout, input validation, outgoing requests.
 */
final class SecurityTest extends TestCase
{
    private function app(string $env = 'dev'): App
    {
        $dir = $this->tempDir();
        $app = new App(Config::fromArray([
            'env' => $env,
            'base_url' => 'https://checkin.test',
            'db_path' => "$dir/app.sqlite",
            'data_dir' => $dir,
        ]), installed: true);
        $app->migrator()->migrate();
        $app->settings()->set('password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
        $_SESSION = [];
        $_COOKIE = [];
        return $app;
    }

    private function service(): CheckinService
    {
        return new CheckinService(new CheckinRepository($this->migratedPdo()), 'Europe/Berlin');
    }

    // ---- Proxies -------------------------------------------------------------

    public function testCidrMatching(): void
    {
        self::assertTrue(IpRange::matches('10.1.2.3', '10.0.0.0/8'));
        self::assertTrue(IpRange::matches('172.31.255.1', '172.16.0.0/12'));
        self::assertFalse(IpRange::matches('172.32.0.1', '172.16.0.0/12'));
        self::assertTrue(IpRange::matches('127.0.0.1', '127.0.0.1'));
        self::assertTrue(IpRange::matches('fd00::1', 'fc00::/7'));
        self::assertFalse(IpRange::matches('1.2.3.4', '::1/128'));
    }

    public function testPublicAddressCheck(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '100.64.0.1', '::1', 'fe80::1', '::ffff:10.0.0.1', '0.0.0.0'] as $ip) {
            self::assertFalse(IpRange::isPublic($ip), $ip);
        }
        self::assertTrue(IpRange::isPublic('1.1.1.1'));
        self::assertTrue(IpRange::isPublic('2606:4700::1111'));
        self::assertFalse(IpRange::hostIsPublic('127.0.0.1'));
        self::assertFalse(IpRange::hostIsPublic('[::1]'));
    }

    public function testForwardedHeadersOnlyFromTrustedProxies(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET',
                'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];
            $direct = Request::fromGlobals(IpRange::PRIVATE_NETWORKS);
            self::assertFalse($direct->https, 'A client cannot claim HTTPS');
            self::assertSame('203.0.113.9', $direct->ip, 'A client cannot pick its IP');

            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.1, 10.0.0.2';
            $proxied = Request::fromGlobals(IpRange::PRIVATE_NETWORKS);
            self::assertTrue($proxied->https);
            self::assertSame('198.51.100.1', $proxied->ip, 'Rightmost untrusted hop; forged entries on the left are ignored');

            self::assertFalse(Request::fromGlobals([])->https, 'Empty list trusts nobody');
        } finally {
            $_SERVER = $server;
        }
    }

    public function testHttpsRedirectUsesConfiguredHost(): void
    {
        $app = $this->app('prod');
        $response = $app->handle(new Request('GET', '/checkins', [], [], ['host' => 'evil.example'], '1.2.3.4', false));
        self::assertSame(301, $response->status);
        self::assertSame('https://checkin.test/checkins', $response->headers['Location'] ?? null);
    }

    public function testCspAllowsOnlyOwnScripts(): void
    {
        $csp = $this->app()->handle(new Request('GET', '/login', [], [], [], '1.2.3.4', true))->headers['Content-Security-Policy'] ?? '';
        self::assertStringNotContainsString('jsdelivr', $csp);
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
    }

    // ---- CSRF and sessions -----------------------------------------------------

    public function testCsrfTokenIsOnlyReadFromThePostBody(): void
    {
        $app = $this->app();
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));
        $token = $app->session()->csrfToken();

        $viaQuery = $app->handle(new Request('POST', '/logout', ['_csrf' => $token], [], [], '1.2.3.4', true));
        self::assertSame(400, $viaQuery->status);
        $viaBody = $app->handle(new Request('POST', '/logout', [], ['_csrf' => $token], [], '1.2.3.4', true));
        self::assertSame(303, $viaBody->status);
    }

    public function testRotatingTheEpochEndsSessions(): void
    {
        $app = $this->app();
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));
        self::assertTrue($app->auth()->check());

        Auth::rotateEpoch($app->settings());
        self::assertFalse($app->auth()->check(), 'Old session is no longer valid');
    }

    public function testSessionsFromBeforeTheUpdateAreInvalid(): void
    {
        $app = $this->app();
        $_SESSION = ['auth' => true]; // what a pre-update session looks like
        $app->session()->start();
        self::assertFalse($app->auth()->check());
    }

    public function testSetupResetEndsAllSessions(): void
    {
        $dir = $this->tempDir();
        mkdir("$dir/sessions");
        file_put_contents("$dir/sessions/sess_abc", 'auth|b:1;');
        $setup = new Setup("$dir/config.php", $dir, APP_ROOT . '/migrations');
        $setup->run(['password' => 'new password!', 'contact_email' => 'a@b.test', 'base_url' => 'https://x.test']);

        self::assertFileDoesNotExist("$dir/sessions/sess_abc");
        $pdo = \App\Database\Connection::open("$dir/app.sqlite");
        self::assertNotSame('', (string) (new SettingsRepository($pdo))->get('session_epoch', ''));
    }

    public function testRequestsWithoutCookieDoNotNeedASession(): void
    {
        $session = new Session($this->tempDir(), true);
        $_COOKIE = [];
        self::assertFalse($session->exists());
        self::assertNull($session->get('auth'));
        self::assertSame([], $session->takeFlash());
        self::assertFalse($session->validCsrf('x'));
        self::assertFalse($session->exists(), 'Reading did not start a session');
        self::assertSame('__Host-svaerma_sid', $session->cookieName());
    }

    // ---- Lockout ---------------------------------------------------------------

    public function testStrangersCannotLockOutAKnownDevice(): void
    {
        $app = $this->app();
        $now = 2_000_000;
        // 25 failures from 25 different IPs trigger the global lock …
        for ($i = 0; $i < Auth::MAX_GLOBAL; $i++) {
            $app->auth()->attempt('nope', "198.51.100.$i", $now, false);
        }
        self::assertSame('locked', $app->auth()->attempt('correct horse', '203.0.113.1', $now, false));
        // … but not for a device that logged in before.
        self::assertSame('ok', $app->auth()->attempt('correct horse', '203.0.113.1', $now, true));
    }

    public function testKnownDeviceCookieMustMatchTheEpoch(): void
    {
        $app = $this->app();
        $_COOKIE[Auth::DEVICE_COOKIE] = 'forged';
        self::assertFalse($app->auth()->knownDevice());
    }

    public function testApiAndLoginFailuresAreCountedSeparately(): void
    {
        $repo = new LoginAttemptRepository($this->migratedPdo());
        $repo->record('1.2.3.4', 100);
        $repo->record('1.2.3.4', 100, LoginAttemptRepository::API);
        $repo->record('1.2.3.4', 100, LoginAttemptRepository::API);

        self::assertSame(1, $repo->countSince(0, '1.2.3.4'));
        self::assertSame(1, $repo->countSince(0));
        self::assertSame(2, $repo->countSince(0, '1.2.3.4', LoginAttemptRepository::API));
        self::assertSame(2, $repo->countSince(0, null, LoginAttemptRepository::API));
    }

    public function testApiNearbyIsRateLimitedPerToken(): void
    {
        $app = $this->app();
        for ($i = 0; $i < App::API_NEARBY_PER_MINUTE; $i++) {
            self::assertTrue($app->rateLimit('nearby:1', App::API_NEARBY_PER_MINUTE));
        }
        self::assertFalse($app->rateLimit('nearby:1', App::API_NEARBY_PER_MINUTE));
        self::assertTrue($app->rateLimit('nearby:2', App::API_NEARBY_PER_MINUTE), 'Other tokens are not affected');
    }

    // ---- Input validation --------------------------------------------------------

    public function testNonStringValuesAreRejected(): void
    {
        $errors = $this->service()->validate(['lat' => 1, 'lon' => 1, 'place_name' => ['x']])['errors'];
        self::assertSame(['Ungültiger Wert für place_name.'], $errors);

        $errors = $this->service()->validate(['lat' => [1], 'lon' => 1, 'place_name' => 'X', 'note' => ['a' => 1]])['errors'];
        self::assertContains('Ungültiger Wert für note.', $errors);
        self::assertContains('Ungültige Koordinaten.', $errors);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $errors = $this->service()->validate(['lat' => 1, 'lon' => 1, 'place_name' => "Caf\xE9"])['errors'];
        self::assertSame(['Ungültige Zeichenkodierung in place_name (UTF-8 erwartet).'], $errors);
    }

    public function testJsonResponsesSurviveInvalidUtf8(): void
    {
        $response = Response::json(['name' => "Caf\xE9"]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Caf', $response->body);
    }

    public function testTimeIsBounded(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00', new \DateTimeZone('UTC'));
        $base = ['lat' => 1, 'lon' => 1, 'place_name' => 'X'];
        foreach (['+9000 years', '20261029', '1999-12-31', '2026-11-01 12:00'] as $time) {
            $result = $this->service()->validate($base + ['checked_in_at' => $time], $now);
            self::assertNull($result['data'], $time);
        }
        foreach (['2009-03-11 10:00', '2026-10-30 10:00', '1700000000'] as $time) {
            self::assertNotNull($this->service()->validate($base + ['checked_in_at' => $time], $now)['data'], $time);
        }
    }

    // ---- Correctness ---------------------------------------------------------------

    public function testSwarmItemsWithoutIdDoNotCollideAcrossFiles(): void
    {
        $repo = new CheckinRepository($this->migratedPdo());
        $importer = new SwarmImporter($repo, new CheckinService($repo, 'Europe/Berlin'), 'Europe/Berlin');
        $item = static fn (string $name, int $time): array => ['createdAt' => $time, 'venue' => ['name' => $name], 'lat' => 52.5, 'lng' => 13.4];

        $first = $importer->import(['a.json' => (string) json_encode(['items' => [$item('A', 1500000000)]])], false);
        $second = $importer->import(['b.json' => (string) json_encode(['items' => [$item('B', 1500000100)]])], false);
        $again = $importer->import(['b.json' => (string) json_encode(['items' => [$item('B', 1500000100)]])], false);

        self::assertSame(1, $first['imported']);
        self::assertSame(1, $second['imported'], 'Position "#1" in another file is not a duplicate');
        self::assertSame(1, $again['duplicates'], 'The same entry is still recognised');
    }

    public function testPhotonPlacesWithoutOsmReferenceAreKept(): void
    {
        $features = [];
        foreach (['Eins', 'Zwei'] as $i => $name) {
            $features[] = ['properties' => ['name' => $name, 'osm_key' => 'amenity', 'osm_value' => 'cafe'], 'geometry' => ['coordinates' => [13.405 + $i / 10000, 52.52]]];
        }
        $http = new FakeHttpClient((string) json_encode(['features' => $features]));
        $search = new PlaceSearch($http, new CacheRepository($this->migratedPdo()), 'photon', 'https://p.test/api', 'https://p.test/reverse', 'https://n.test', new Throttle($this->tempDir() . '/t', 0.0), 60);

        self::assertCount(2, $search->nearby(52.52, 13.405, 150));
    }

    public function testNearbyResultsAreCapped(): void
    {
        $places = [];
        for ($i = 0; $i < NearbySearch::MAX_PLACES + 50; $i++) {
            $places[] = new Place("P$i", 52.52, 13.405, 'node', $i + 1, null, null, (float) $i);
        }
        $search = new NearbySearch(['overpass' => static fn (): array => $places], static function (string $m): void {
        });
        self::assertCount(NearbySearch::MAX_PLACES, $search->find(52.52, 13.405, 1000)['places']);
    }

    public function testEditingKeepsSecondsWhenTimeIsUnchanged(): void
    {
        $app = $this->app();
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));
        $checkin = $app->checkinService()->create(['lat' => 1, 'lon' => 1, 'place_name' => 'X', 'checked_in_at' => '2026-10-29T10:15:42Z'], 'web');
        self::assertInstanceOf(Checkin::class, $checkin);

        $app->handle(new Request('POST', "/checkin/{$checkin->id}/edit", [], [
            '_csrf' => $app->session()->csrfToken(),
            'place_name' => 'X',
            'note' => 'nur die Notiz',
            'checked_in_at' => $checkin->localTime()->format('Y-m-d\TH:i'),
            'timezone' => $checkin->timezone,
        ], [], '1.2.3.4', true));

        $updated = $app->checkins()->find($checkin->id);
        self::assertSame('nur die Notiz', $updated?->note);
        self::assertSame('2026-10-29 10:15:42', $updated->checkedInAt->format('Y-m-d H:i:s'));
    }

    // ---- Outgoing requests and setup -------------------------------------------------

    public function testInternalOverpassUrlsAreRejected(): void
    {
        $app = $this->app();
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));
        $app->handle(new Request('POST', '/settings', [], [
            '_csrf' => $app->session()->csrfToken(),
            'nearby_radius' => '150',
            'overpass_extra_urls' => "https://127.0.0.1/api/interpreter\nhttps://169.254.169.254/latest",
        ], [], '1.2.3.4', true));

        self::assertSame('', (string) $app->settings()->get('overpass_extra_urls', ''));
        $messages = array_column($app->session()->takeFlash(), 'message');
        self::assertCount(2, array_filter($messages, static fn (string $m): bool => str_contains($m, 'interne Adresse')));
    }

    public function testSetupDetectsAPublicDataDirectory(): void
    {
        $dir = $this->tempDir();
        $setup = new Setup("$dir/config.php", $dir, APP_ROOT . '/migrations');

        // A web server that serves files from data/ as they are.
        $exposed = new FakeHttpClient(static fn (string $url): string => (string) @file_get_contents($dir . '/' . basename($url)));
        self::assertTrue($setup->dataDirExposed($exposed, 'https://x.test'));
        self::assertSame(['https://x.test/data/'], [dirname($exposed->requests[0]['url']) . '/']);

        $protected = new FakeHttpClient(static fn (): string => throw new OsmException('HTTP 403'));
        self::assertNotTrue($setup->dataDirExposed($protected, 'https://x.test'));
        self::assertSame([], glob("$dir/probe-*") ?: [], 'Probe file is removed');
    }
}
