<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ApiTokenRepository;

/**
 * Kernel-level tests: routing, bearer-token auth and the JSON API.
 */
final class ApiTest extends TestCase
{
    private App $app;
    private string $token;

    protected function setUp(): void
    {
        $dir = $this->tempDir();
        $this->app = new App(Config::fromArray([
            'env' => 'dev',
            'base_url' => 'https://checkin.test',
            'db_path' => "$dir/app.sqlite",
            'data_dir' => $dir,
        ]), installed: true);
        $this->app->migrator()->migrate();
        $this->token = $this->app->apiTokens()->create('Test');
        $_SESSION = [];
    }

    /** @param array<string, mixed> $query */
    private function call(string $method, string $path, ?string $token = null, array $query = [], string $body = ''): Response
    {
        $headers = ['accept' => 'application/json', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        return $this->app->handle(new Request($method, $path, $query, [], $headers, '10.0.0.1', true, $body));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        return $data;
    }

    public function testRejectsMissingAndWrongTokens(): void
    {
        self::assertSame(401, $this->call('GET', '/api/v1/checkins')->status);
        self::assertSame(401, $this->call('GET', '/api/v1/checkins', 'svm_wrong')->status);
        self::assertSame('Bearer', $this->call('GET', '/api/v1/checkins')->headers['WWW-Authenticate'] ?? null);
    }

    public function testRevokedTokenNoLongerWorks(): void
    {
        self::assertSame(200, $this->call('GET', '/api/v1/checkins', $this->token)->status);
        $this->app->apiTokens()->revoke($this->app->apiTokens()->all()[0]['id']);
        self::assertSame(401, $this->call('GET', '/api/v1/checkins', $this->token)->status);
    }

    public function testTooManyWrongTokensLockOnlyFurtherWrongTokens(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->call('GET', '/api/v1/checkins', 'svm_wrong');
        }
        self::assertSame(429, $this->call('GET', '/api/v1/checkins', 'svm_wrong')->status);
        // Others guessing tokens must not lock out the owner's shortcut.
        self::assertSame(200, $this->call('GET', '/api/v1/checkins', $this->token)->status);
    }

    public function testWrongTokensDoNotLockTheLoginForm(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->call('GET', '/api/v1/checkins', 'svm_wrong');
        }
        self::assertFalse($this->app->auth()->isLocked('10.0.0.1'));
        self::assertFalse($this->app->auth()->isLocked('10.9.9.9'));
    }

    public function testCreateWithFieldsAndRead(): void
    {
        $response = $this->call('POST', '/api/v1/checkins', $this->token, [], (string) json_encode([
            'lat' => 52.52, 'lon' => 13.405, 'place_name' => 'Café Müller', 'osm_type' => 'node', 'osm_id' => 1001,
            'note' => 'per Kurzbefehl', 'checked_in_at' => '2026-10-29T10:00:00Z', 'timezone' => 'Europe/Berlin',
        ]));
        self::assertSame(201, $response->status);
        $created = $this->json($response);
        self::assertSame('api', $created['source']);
        self::assertSame('2026-10-29T10:00:00Z', $created['checked_in_at']);
        self::assertSame('2026-10-29T11:00:00+01:00', $created['local_time']);
        self::assertSame('https://checkin.test/checkin/' . $created['id'] . '-cafe-mueller', $created['url']);
        self::assertSame('https://checkin.test/api/v1/checkins/' . $created['id'], $response->headers['Location'] ?? null);

        $one = $this->json($this->call('GET', '/api/v1/checkins/' . $created['id'], $this->token));
        self::assertSame('Café Müller', $one['place_name']);

        $list = $this->json($this->call('GET', '/api/v1/checkins', $this->token, ['limit' => '1']));
        self::assertSame(1, $list['total']);
        self::assertNull($list['next']);
        self::assertSame(404, $this->call('GET', '/api/v1/checkins/999', $this->token)->status);
    }

    public function testCreateFromPlaceObject(): void
    {
        // Exactly what Shortcuts sends after "Choose from List" on the nearby "choices".
        $place = ['name' => 'Späti Eck', 'lat' => 52.52, 'lon' => 13.405, 'osm_type' => 'node', 'osm_id' => 1003,
            'category' => 'Kiosk', 'distance' => 3, 'visits' => 0, 'last_visit' => null, 'label' => 'Späti Eck · Kiosk · 3 m'];
        $created = $this->json($this->call('POST', '/api/v1/checkins', $this->token, [], (string) json_encode(['place' => $place, 'note' => ''])));
        self::assertSame('Späti Eck', $created['place_name']);
        self::assertSame(1003, $created['osm_id']);
        self::assertNull($created['note']);
    }

    public function testCreateFromPlaceAsJsonText(): void
    {
        // A dictionary variable in a Shortcuts Text field arrives as JSON text.
        $place = (string) json_encode(['name' => 'Späti Eck', 'lat' => 52.52, 'lon' => 13.405, 'osm_type' => 'node', 'osm_id' => 1003]);
        $response = $this->call('POST', '/api/v1/checkins', $this->token, [], (string) json_encode(['place' => $place, 'note' => 'Mate']));
        self::assertSame(201, $response->status);
        self::assertSame('Späti Eck', $this->json($response)['place_name']);
        self::assertSame('Mate', $this->json($response)['note']);
    }

    public function testEmptyOrBrokenPlaceIsNamedInTheError(): void
    {
        // An empty dictionary is what Shortcuts sends when the Dictionary field was never filled.
        foreach (['{"place": {}}', '{"place": ""}', '{"place": "Späti Eck"}', '{"place": 42}'] as $body) {
            $response = $this->call('POST', '/api/v1/checkins', $this->token, [], $body);
            self::assertSame(422, $response->status, $body);
            self::assertStringContainsString('place', $this->json($response)['error'], $body);
        }
    }

    public function testValidationErrorsAre422(): void
    {
        $response = $this->call('POST', '/api/v1/checkins', $this->token, [], '{"lat": 999}');
        self::assertSame(422, $response->status);
        self::assertNotEmpty($this->json($response)['errors']);
    }

    public function testApiPostNeedsNoCsrfButWebPostDoes(): void
    {
        self::assertSame(422, $this->call('POST', '/api/v1/checkins', $this->token, [], '{}')->status);
        self::assertSame(400, $this->app->handle(new Request('POST', '/checkin', [], ['lat' => '1'], [], '10.0.0.2', true))->status);
    }

    public function testNearbyNeedsCoordinates(): void
    {
        self::assertSame(422, $this->call('GET', '/api/v1/places/nearby', $this->token)->status);
    }

    public function testTokensAreStoredOnlyAsHash(): void
    {
        $pdo = $this->app->pdo();
        $stored = (string) $pdo->query('SELECT token_hash FROM api_tokens')->fetchColumn();
        self::assertStringStartsWith(ApiTokenRepository::PREFIX, $this->token);
        self::assertSame(hash('sha256', $this->token), $stored);
        self::assertStringNotContainsString($this->token, (string) json_encode($pdo->query('SELECT * FROM api_tokens')->fetchAll()));
    }

    public function testUnknownApiPathIsJson404(): void
    {
        $response = $this->call('GET', '/api/v1/nope', $this->token);
        self::assertSame(404, $response->status);
        self::assertArrayHasKey('error', $this->json($response));
    }
}
