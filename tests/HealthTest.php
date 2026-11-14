<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Http\Request;

final class HealthTest extends TestCase
{
    public function testHealthIsPublic(): void
    {
        $dir = $this->tempDir();
        $app = new App(Config::fromArray(['env' => 'dev', 'db_path' => "$dir/app.sqlite", 'data_dir' => $dir]), installed: true);
        $app->migrator()->migrate();
        $_SESSION = [];

        $response = $app->handle(new Request('GET', '/health', [], [], [], '10.0.0.1', true));

        self::assertSame(200, $response->status, 'Reachable without login');
        self::assertSame("ok\n", $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testHealthAnswersWhileMigrationsArePending(): void
    {
        $dir = $this->tempDir();
        $app = new App(Config::fromArray(['env' => 'dev', 'db_path' => "$dir/app.sqlite", 'data_dir' => $dir]), installed: true);
        $app->migrator()->migrate();
        // Pretend the newest migration has not run yet, as right after a deploy.
        $app->pdo()->exec("DELETE FROM schema_migrations WHERE version = (SELECT MAX(version) FROM schema_migrations)");
        $_SESSION = [];

        self::assertNotSame([], $app->migrator()->pending());
        $response = $app->handle(new Request('GET', '/health', [], [], [], '10.0.0.1', true));

        self::assertSame(200, $response->status, 'A deploy must not fail just because a migration is due');
    }

    public function testHealthRejectsPost(): void
    {
        $dir = $this->tempDir();
        $app = new App(Config::fromArray(['env' => 'dev', 'db_path' => "$dir/app.sqlite", 'data_dir' => $dir]), installed: true);
        $_SESSION = [];

        $response = $app->handle(new Request('POST', '/health', [], [], [], '10.0.0.1', true));

        self::assertSame(405, $response->status);
    }
}
