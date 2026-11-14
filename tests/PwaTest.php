<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Http\Request;

final class PwaTest extends TestCase
{
    public function testServiceWorkerIsVersionedAndPublic(): void
    {
        $dir = $this->tempDir();
        $app = new App(Config::fromArray(['env' => 'dev', 'db_path' => "$dir/app.sqlite", 'data_dir' => $dir]), installed: true);
        $app->migrator()->migrate();
        $_SESSION = [];

        $response = $app->handle(new Request('GET', '/sw.js', [], [], [], '10.0.0.1', true));

        self::assertSame(200, $response->status, 'Reachable without login');
        self::assertStringStartsWith('application/javascript', $response->headers['Content-Type']);
        self::assertStringContainsString("const CACHE = 'svaerma-" . App::VERSION . "';", $response->body);
        self::assertStringContainsString('"/assets/js/app.js?v=' . App::VERSION . '"', $response->body);
        self::assertStringNotContainsString('__PRECACHE__', $response->body);
    }

    public function testManifestIsValidAndIconsExist(): void
    {
        $manifest = json_decode((string) file_get_contents(APP_ROOT . '/public/manifest.webmanifest'), true);
        self::assertIsArray($manifest);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/', $manifest['start_url']);
        foreach ($manifest['icons'] as $icon) {
            self::assertFileExists(APP_ROOT . '/public' . $icon['src']);
        }
        self::assertContains('maskable', array_column($manifest['icons'], 'purpose'));
    }

    public function testPrecachedFilesExist(): void
    {
        foreach (['offline.html', 'assets/icons/apple-touch-icon.png', 'assets/css/core.css', 'assets/vendor/bricolage-grotesque-5.2.10/latin.woff2', 'assets/js/checkin.js', 'assets/js/map.js'] as $file) {
            self::assertFileExists(APP_ROOT . '/public/' . $file);
        }
    }
}
