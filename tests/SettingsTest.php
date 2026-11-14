<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Http\Request;
use App\Http\Response;

/**
 * Settings page: every accordion section saves only its own fields.
 */
final class SettingsTest extends TestCase
{
    private function app(): App
    {
        $dir = $this->tempDir();
        $app = new App(Config::fromArray([
            'env' => 'dev',
            'base_url' => 'https://checkin.test',
            'db_path' => "$dir/app.sqlite",
            'data_dir' => $dir,
        ]), installed: true);
        $app->migrator()->migrate();
        $app->settings()->set('password_hash', password_hash('correct horse', PASSWORD_DEFAULT));
        $_SESSION = [];
        $_COOKIE = [];
        self::assertSame('ok', $app->auth()->attempt('correct horse', '1.2.3.4'));
        return $app;
    }

    /** @param array<string, string> $fields */
    private function post(App $app, array $fields): Response
    {
        return $app->handle(new Request('POST', '/settings', [], ['_csrf' => $app->session()->csrfToken()] + $fields, [], '1.2.3.4', true));
    }

    public function testASectionLeavesOtherSectionsAlone(): void
    {
        $app = $this->app();
        $settings = $app->settings();
        $settings->set('nearby_radius', '300');
        $settings->set('public_enabled', '1');
        $settings->set('geoapify_key', 'abcdefghijklmnop1234');

        // The design form has no radius, no public switch and no keys.
        $response = $this->post($app, ['section' => 'design', 'theme' => '']);

        self::assertSame('/settings?open=design#design', $response->headers['Location']);
        self::assertSame('300', $settings->get('nearby_radius'));
        self::assertSame('1', $settings->get('public_enabled'));
        self::assertSame('abcdefghijklmnop1234', $settings->get('geoapify_key'));
        self::assertSame('', $settings->get('theme'));
    }

    public function testUncheckedBoxesOnlyCountInTheirOwnSection(): void
    {
        $app = $this->app();
        $settings = $app->settings();
        $settings->set('public_enabled', '1');

        $this->post($app, ['section' => 'checkin', 'nearby_radius' => '200']);
        self::assertSame('200', $settings->get('nearby_radius'));
        self::assertSame('1', $settings->get('public_enabled'), 'Saving the radius does not switch public mode off');

        $this->post($app, ['section' => 'public', 'public_delay_hours' => '4']);
        self::assertSame('0', $settings->get('public_enabled'), 'Unchecked switch in its own section turns it off');
        self::assertSame('4', $settings->get('public_delay_hours'));
        self::assertSame('200', $settings->get('nearby_radius'));
    }

    public function testInvalidInputKeepsTheOldValueAndReopensTheSection(): void
    {
        $app = $this->app();
        $app->settings()->set('nearby_radius', '300');

        $response = $this->post($app, ['section' => 'checkin', 'nearby_radius' => '5']);

        self::assertSame('/settings?open=checkin#checkin', $response->headers['Location']);
        self::assertSame('300', $app->settings()->get('nearby_radius'));
    }

    public function testUnknownSectionIsRejected(): void
    {
        $app = $this->app();
        $app->settings()->set('nearby_radius', '300');

        $response = $this->post($app, ['section' => 'password_hash', 'nearby_radius' => '200']);

        self::assertSame(400, $response->status);
        self::assertSame('300', $app->settings()->get('nearby_radius'));
    }

    public function testOpenParameterOpensOnlyKnownSections(): void
    {
        $app = $this->app();
        $get = static fn (string $query): string => $app->handle(new Request('GET', '/settings', ['open' => $query], [], [], '1.2.3.4', true))->body;

        self::assertMatchesRegularExpression('/<details[^>]*id="public" open>/', $get('public'));
        self::assertDoesNotMatchRegularExpression('/<details[^>]* open>/', $get('<script>'));
        self::assertStringNotContainsString('<script>', $get('<script>'));
    }
}
