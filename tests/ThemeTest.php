<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Http\Request;
use App\Service\Themes;

/**
 * User themes in data/themes/: listing, safe delivery, choosing one in the settings.
 */
final class ThemeTest extends TestCase
{
    private string $dir;

    private function app(): App
    {
        $this->dir = $this->tempDir();
        $app = new App(Config::fromArray([
            'env' => 'dev',
            'base_url' => 'https://checkin.test',
            'db_path' => "{$this->dir}/app.sqlite",
            'data_dir' => $this->dir,
        ]), installed: true);
        $app->migrator()->migrate();
        $app->settings()->set('password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
        $_SESSION = [];
        $_COOKIE = [];
        return $app;
    }

    /** @param array<string, string> $files */
    private function theme(string $slug, string $css, array $files = []): void
    {
        $base = "{$this->dir}/themes/$slug";
        @mkdir($base, 0700, true);
        file_put_contents("$base/theme.css", $css);
        foreach ($files as $name => $content) {
            @mkdir(dirname("$base/$name"), 0700, true);
            file_put_contents("$base/$name", $content);
        }
    }

    private function get(App $app, string $path): \App\Http\Response
    {
        return $app->handle(new Request('GET', $path, [], [], [], '1.2.3.4', true));
    }

    public function testThemesAreListedWithTheirHeader(): void
    {
        $app = $this->app();
        $this->theme('nachtschwarm', "/*\n * Name: Nachtschwarm\n * Author: Jemand\n * Version: 1.0\n * sv-theme-api: 1\n */\n:root{}");
        $this->theme('alt', "/* Name: Altes Design\n   sv-theme-api: 0 */");
        $this->theme('ohne-kopf', ':root{}');
        @mkdir("{$this->dir}/themes/leer", 0700, true);          // no theme.css
        @mkdir("{$this->dir}/themes/Gross", 0700, true);         // invalid name
        file_put_contents("{$this->dir}/themes/Gross/theme.css", '');

        $all = $app->themes()->all();

        self::assertSame(['alt', 'nachtschwarm', 'ohne-kopf'], array_keys($all), 'Sorted by name, invalid folders skipped');
        self::assertSame('Nachtschwarm', $all['nachtschwarm']['name']);
        self::assertSame('Jemand', $all['nachtschwarm']['author']);
        self::assertSame('1.0', $all['nachtschwarm']['version']);
        self::assertTrue($all['nachtschwarm']['compatible']);
        self::assertFalse($all['alt']['compatible'], 'Other API version is listed, but flagged');
        self::assertSame('ohne-kopf', $all['ohne-kopf']['name']);
        self::assertNull($all['ohne-kopf']['api']);
    }

    public function testNoThemeFolderMeansNoThemes(): void
    {
        self::assertSame([], $this->app()->themes()->all());
    }

    public function testThemeFilesAreServedWithoutLogin(): void
    {
        $app = $this->app();
        $this->theme('nacht', '/* sv-theme-api: 1 */ body{}', ['fonts/space.woff2' => 'wOF2', 'map.css' => '.x{}']);

        $css = $this->get($app, '/themes/nacht/theme.css');
        self::assertSame(200, $css->status);
        self::assertSame('text/css; charset=utf-8', $css->headers['Content-Type']);
        self::assertSame('nosniff', $css->headers['X-Content-Type-Options']);
        self::assertStringContainsString('body{}', $css->body);

        $font = $this->get($app, '/themes/nacht/fonts/space.woff2');
        self::assertSame(200, $font->status);
        self::assertSame('font/woff2', $font->headers['Content-Type']);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenPaths(): iterable
    {
        yield 'database' => ['/themes/nacht/../../app.sqlite'];
        yield 'encoded traversal' => ['/themes/nacht/..%2F..%2Fapp.sqlite'];
        yield 'php file' => ['/themes/nacht/evil.php'];
        yield 'svg' => ['/themes/nacht/logo.svg'];
        yield 'hidden file' => ['/themes/nacht/.secret.css'];
        yield 'deep folder' => ['/themes/nacht/a/b/c.css'];
        yield 'unknown theme' => ['/themes/fehlt/theme.css'];
        yield 'missing file' => ['/themes/nacht/fehlt.css'];
        yield 'symlink out of theme' => ['/themes/nacht/link.css'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenPaths')]
    public function testOnlyThemeFilesCanBeFetched(string $path): void
    {
        $app = $this->app();
        $this->theme('nacht', 'body{}', ['evil.php' => '<?php', 'logo.svg' => '<svg/>', '.secret.css' => 'x', 'a/b/c.css' => 'x']);
        file_put_contents("{$this->dir}/outside.css", 'secret');
        @symlink("{$this->dir}/outside.css", "{$this->dir}/themes/nacht/link.css");

        $response = $this->get($app, $path);

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('secret', $response->body);
    }

    public function testChosenThemeIsLinkedAfterTheCoreStyles(): void
    {
        $app = $this->app();
        $this->theme('nacht', '/* sv-theme-api: 1 */');
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));

        $app->handle(new Request('POST', '/settings', [], [
            '_csrf' => $app->session()->csrfToken(),
            'nearby_radius' => '150',
            'theme' => 'nacht',
        ], [], '1.2.3.4', true));
        self::assertSame('nacht', $app->settings()->get('theme'));

        $html = $this->get($app, '/settings')->body;
        self::assertMatchesRegularExpression('#css/app\.css.*/themes/nacht/theme\.css\?v=\d+#s', $html);
        self::assertStringContainsString('<option value="nacht" selected>', preg_replace('/\s+>/', '>', $html) ?? '');
    }

    public function testUnknownThemeIsRejected(): void
    {
        $app = $this->app();
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));

        $app->handle(new Request('POST', '/settings', [], [
            '_csrf' => $app->session()->csrfToken(),
            'nearby_radius' => '150',
            'theme' => '../../app',
        ], [], '1.2.3.4', true));

        self::assertNull($app->settings()->get('theme'));
    }

    public function testRemovedThemeFallsBackToTheDefault(): void
    {
        $app = $this->app();
        $app->settings()->set('theme', 'weg');

        self::assertNull($app->activeTheme());
        self::assertStringNotContainsString('/themes/', $this->get($app, '/login')->body);
    }

    public function testSlugRules(): void
    {
        self::assertTrue(Themes::validSlug('nachtschwarm'));
        self::assertTrue(Themes::validSlug('a-1'));
        self::assertFalse(Themes::validSlug('-a'));
        self::assertFalse(Themes::validSlug('a-'));
        self::assertFalse(Themes::validSlug('A'));
        self::assertFalse(Themes::validSlug('..'));
        self::assertFalse(Themes::validSlug(str_repeat('a', 41)));
    }
}
