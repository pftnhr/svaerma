<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;

final class ConfigTest extends TestCase
{
    public function testLocalValuesOverrideDefaultsRecursively(): void
    {
        $config = Config::fromArray(['osm' => ['photon_url' => 'https://photon.local/api']]);
        self::assertSame('https://photon.local/api', $config->get('osm.photon_url'));
        self::assertSame('https://overpass-api.de/api/interpreter', $config->get('osm.overpass_url'), 'Untouched defaults remain');
    }

    public function testListsAreReplacedNotMerged(): void
    {
        self::assertSame([], Config::fromArray(['osm' => ['overpass_fallback_urls' => []]])->get('osm.overpass_fallback_urls'));
        self::assertSame(['https://x.test'], Config::fromArray(['osm' => ['overpass_fallback_urls' => ['https://x.test']]])->get('osm.overpass_fallback_urls'));
    }

    public function testMissingKeyReturnsDefault(): void
    {
        self::assertSame('fallback', Config::fromArray([])->get('osm.nope.deeper', 'fallback'));
    }

    public function testConfigPathCanBeOverriddenByEnvironment(): void
    {
        putenv('APP_CONFIG=/tmp/elsewhere/config.php');
        try {
            self::assertSame('/tmp/elsewhere/config.php', \App\Config::path());
        } finally {
            putenv('APP_CONFIG');
        }
        self::assertStringEndsWith('/config/config.php', \App\Config::path());
    }
}
