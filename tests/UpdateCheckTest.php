<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\CacheRepository;
use App\Service\Osm\OsmException;
use App\Service\UpdateCheck;

final class UpdateCheckTest extends TestCase
{
    private function check(FakeHttpClient $http, string $current = '0.8.1', ?CacheRepository $cache = null): UpdateCheck
    {
        return new UpdateCheck($http, $cache ?? new CacheRepository($this->migratedPdo()), 'owner/repo', $current);
    }

    public function testReportsANewerRelease(): void
    {
        $http = new FakeHttpClient('{"tag_name": "v0.9.0", "html_url": "https://evil.example/"}');
        self::assertSame(
            ['version' => '0.9.0', 'url' => 'https://github.com/owner/repo/releases/tag/v0.9.0'],
            $this->check($http)->newerRelease(),
            'Link is built from the repository, never taken from the response',
        );
        self::assertSame('https://api.github.com/repos/owner/repo/releases/latest', $http->requests[0]['url']);
    }

    public function testSameOrOlderVersionIsNoUpdate(): void
    {
        self::assertNull($this->check(new FakeHttpClient('{"tag_name": "v0.8.1"}'))->newerRelease());
        self::assertNull($this->check(new FakeHttpClient('{"tag_name": "0.8.0"}'))->newerRelease());
        self::assertNotNull($this->check(new FakeHttpClient('{"tag_name": "v0.8.10"}'))->newerRelease(), 'Compared as versions, not strings');
    }

    public function testAsksGitHubAtMostOncePerInterval(): void
    {
        $cache = new CacheRepository($this->migratedPdo());
        $http = new FakeHttpClient('{"tag_name": "v1.0.0"}');
        $this->check($http, '0.8.1', $cache)->newerRelease();
        $this->check($http, '0.8.1', $cache)->newerRelease();
        self::assertCount(1, $http->requests);
    }

    public function testFailuresAndOddTagsAreSilentAndCached(): void
    {
        $cache = new CacheRepository($this->migratedPdo());
        $down = new FakeHttpClient(static fn (): string => throw new OsmException('HTTP 503'));
        self::assertNull($this->check($down, '0.8.1', $cache)->newerRelease());
        self::assertNull($this->check($down, '0.8.1', $cache)->newerRelease());
        self::assertCount(1, $down->requests, 'A failure is not retried on every page view');

        self::assertNull($this->check(new FakeHttpClient('{"tag_name": "nightly-<b>"}'))->newerRelease());
        self::assertNull($this->check(new FakeHttpClient('not json'))->newerRelease());
    }
}
