<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\CacheRepository;
use App\Service\Osm\Categories;
use App\Service\Osm\Geoapify;
use App\Service\Osm\NearbySearch;
use App\Model\Place;
use App\Service\Osm\Geo;
use App\Service\Osm\OsmException;
use App\Service\Osm\Overpass;
use App\Service\Osm\PlaceSearch;
use App\Service\Osm\Throttle;

final class OsmTest extends TestCase
{
    /** @return list<array{url: string, label: string}> */
    private static function endpoints(string ...$urls): array
    {
        return array_map(static fn (string $u): array => ['url' => $u, 'label' => (string) parse_url($u, PHP_URL_HOST)], $urls);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/fixtures/$name.json");
    }

    public function testOverpassParsesSortsAndDeduplicates(): void
    {
        $http = new FakeHttpClient($this->fixture('overpass'));
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://overpass.test/api'), 60);

        $places = $overpass->nearby(52.52, 13.405, 150);

        self::assertSame(['Späti Eck', 'Café Müller', 'Buchhandlung Lesezeichen', 'Teeladen'], array_map(fn ($p) => $p->name, $places));
        self::assertSame('Kiosk', $places[0]->category);
        self::assertSame(0, (int) round((float) $places[0]->distance));
        self::assertSame('Rosenthaler Straße 12', $places[1]->address);
        self::assertSame('way', $places[2]->osmType);
        self::assertSame(2002, $places[2]->osmId);
        self::assertSame('Tee', $places[3]->category);
        self::assertSame('Fishing', Categories::label(['shop' => 'fishing']), 'Unknown values fall back to the raw tag');
    }

    public function testOverpassUsesCacheForNearbyRequests(): void
    {
        $http = new FakeHttpClient($this->fixture('overpass'));
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://overpass.test/api'), 60);

        $overpass->nearby(52.52001, 13.40501, 150);
        $overpass->nearby(52.52002, 13.40502, 150); // same ~11 m grid cell
        self::assertCount(1, $http->requests);

        $overpass->nearby(52.53, 13.40501, 150);
        self::assertCount(2, $http->requests);
    }

    public function testOverpassClampsRadiusAndBuildsQuery(): void
    {
        $http = new FakeHttpClient('{"elements": []}');
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://overpass.test/api'), 60);
        $overpass->nearby(52.5, 13.4, 999999);

        $query = (string) $http->requests[0]['data']['data'];
        self::assertStringContainsString('nwr(around:1000,52.500000,13.400000)["name"];', $query);
        self::assertStringContainsString('out tags center qt;', $query);
    }

    public function testFallsBackToNextEndpoint(): void
    {
        $http = new FakeHttpClient(function (string $url): string {
            if (str_contains($url, 'primary')) {
                throw new OsmException('Der Dienst antwortet mit HTTP 504.');
            }
            return $this->fixture('overpass');
        });
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://primary.test/api', 'https://backup.test/api'), 60);

        self::assertCount(4, $overpass->nearby(52.52, 13.405, 150));
        self::assertSame(['https://primary.test/api', 'https://backup.test/api'], array_column($http->requests, 'url'));
    }

    public function testErrorNamesEveryFailedEndpoint(): void
    {
        $http = new FakeHttpClient(static fn (string $url): string => throw new OsmException('HTTP 429'));
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://a.test/api', 'https://b.test/api'), 60);

        $this->expectExceptionMessage('a.test: HTTP 429 | b.test: HTTP 429');
        $overpass->nearby(52.52, 13.405, 150);
    }

    public function testRuntimeErrorRemarkIsNotCached(): void
    {
        $calls = 0;
        $http = new FakeHttpClient(function () use (&$calls): string {
            $calls++;
            return $calls === 1
                ? '{"elements": [], "remark": "runtime error: Query timed out in \"query\" at line 2 after 20 seconds."}'
                : $this->fixture('overpass');
        });
        $cache = new CacheRepository($this->migratedPdo());
        $overpass = new Overpass($http, $cache, self::endpoints('https://overpass.test/api'), 60);

        try {
            $overpass->nearby(52.52, 13.405, 150);
            self::fail('Expected timeout to surface');
        } catch (OsmException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        $cache->set(Overpass::COOLDOWN_KEY, '', -1); // end the cooldown
        self::assertCount(4, $overpass->nearby(52.52, 13.405, 150), 'Second call hits the API again');
    }

    public function testCategoryFilter(): void
    {
        self::assertTrue(Categories::matches(['name' => 'X', 'amenity' => 'cafe']));
        self::assertFalse(Categories::matches(['name' => 'X', 'amenity' => 'bench']));
        self::assertFalse(Categories::matches(['name' => 'Hauptstraße', 'highway' => 'residential']));
        self::assertFalse(Categories::matches(['name' => 'Haus', 'building' => 'yes']));
        self::assertTrue(Categories::matches(['name' => 'Dom', 'building' => 'cathedral']));
        self::assertTrue(Categories::matches(['name' => 'X', 'amenity' => 'bench', 'tourism' => 'viewpoint']));
    }

    public function testOverpassRejectsInvalidResponse(): void
    {
        $overpass = new Overpass(new FakeHttpClient('<html>error</html>'), new CacheRepository($this->migratedPdo()), self::endpoints('https://overpass.test/api'), 60);
        $this->expectException(OsmException::class);
        $overpass->nearby(52.5, 13.4, 100);
    }

    public function testPhotonParsing(): void
    {
        $http = new FakeHttpClient($this->fixture('photon'));
        $search = new PlaceSearch($http, new CacheRepository($this->migratedPdo()), 'photon', 'https://photon.test/api', 'https://photon.test/reverse', 'https://nominatim.test/search', new Throttle($this->tempDir() . '/t', 0.0), 60);

        $places = $search->search('Brandenburger', 52.5163, 13.3777);

        self::assertCount(2, $places, 'Features without name are skipped');
        self::assertSame('Brandenburger Tor', $places[0]->name);
        self::assertSame('way', $places[0]->osmType);
        self::assertSame(518071791, $places[0]->osmId);
        self::assertSame('Sehenswürdigkeit', $places[0]->category);
        self::assertSame('Pariser Platz, Berlin', $places[0]->address);
        self::assertSame('Pariser Platz 1, Berlin', $places[1]->address);
        self::assertNotNull($places[1]->distance);
        self::assertSame('Brandenburger', $http->requests[0]['data']['q']);
        self::assertSame(52.5163, $http->requests[0]['data']['lat']);
    }

    public function testNominatimParsingAndThrottle(): void
    {
        $http = new FakeHttpClient($this->fixture('nominatim'));
        $search = new PlaceSearch($http, new CacheRepository($this->migratedPdo()), 'nominatim', 'https://photon.test/api', 'https://photon.test/reverse', 'https://nominatim.test/search', new Throttle($this->tempDir() . '/t', 0.3), 60);

        $start = microtime(true);
        $places = $search->search('Brandenburger Tor');
        $search->search('Pariser Platz');
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $start, 'Second request waits for the throttle');

        self::assertSame('Brandenburger Tor', $places[0]->name);
        self::assertSame('Pariser Platz', $places[1]->name, 'Falls back to first part of display_name');
        self::assertSame('jsonv2', $http->requests[0]['data']['format']);
    }

    public function testShortQueriesDoNotHitTheNetwork(): void
    {
        $http = new FakeHttpClient('[]');
        $search = new PlaceSearch($http, new CacheRepository($this->migratedPdo()), 'photon', 'p', 'r', 'n', new Throttle($this->tempDir() . '/t', 0.0), 60);
        self::assertSame([], $search->search(' a '));
        self::assertSame([], $http->requests);
    }

    public function testOverpassUsesGet(): void
    {
        $http = new FakeHttpClient('{"elements": []}');
        (new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://overpass.test/api'), 60))->nearby(52.5, 13.4, 100);
        self::assertSame('GET', $http->requests[0]['method']);
    }

    public function testEndpointHeadersAreSentAndLabelsHideSecrets(): void
    {
        $http = new FakeHttpClient(static fn (): string => throw new OsmException('HTTP 503'));
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), [
            ['url' => 'https://api.overpass.test/v1/interpreter', 'label' => 'overpass.ch', 'headers' => ['X-API-Key' => 'SECRET']],
        ], 60);
        try {
            $overpass->fetch('x');
            self::fail('Expected exception');
        } catch (OsmException $e) {
            self::assertSame('overpass.ch: HTTP 503', $e->getMessage());
        }
        self::assertSame(['X-API-Key' => 'SECRET'], $http->headers[0]);
    }

    public function testCooldownSkipsOverpassAfterFailure(): void
    {
        $http = new FakeHttpClient(static fn (): string => throw new OsmException('HTTP 502'));
        $overpass = new Overpass($http, new CacheRepository($this->migratedPdo()), self::endpoints('https://a.test/api', 'https://b.test/api'), 60);

        // A single failure (e.g. a timeout on a large radius) does not block others.
        for ($i = 1; $i <= Overpass::COOLDOWN_AFTER; $i++) {
            try {
                $overpass->nearby(52.52, 13.405 + $i / 100, 150);
            } catch (OsmException $e) {
                self::assertStringNotContainsString('kurz zuvor nicht erreichbar', $e->getMessage());
            }
            self::assertCount(2 * $i, $http->requests);
        }
        $requests = count($http->requests);

        try {
            $overpass->nearby(48.1, 11.5, 150);
            self::fail('Expected cooldown exception');
        } catch (OsmException $e) {
            self::assertStringContainsString('kurz zuvor nicht erreichbar', $e->getMessage());
        }
        self::assertCount($requests, $http->requests, 'No new requests during the cooldown');

        // The diagnostics path (fetch) ignores the cooldown.
        try {
            $overpass->fetch('x');
        } catch (OsmException) {
        }
        self::assertCount($requests + 2, $http->requests);
    }

    public function testPhotonReverseFiltersAndSorts(): void
    {
        $http = new FakeHttpClient($this->fixture('photon-reverse'));
        $search = new PlaceSearch($http, new CacheRepository($this->migratedPdo()), 'nominatim', 'https://photon.test/api', 'https://photon.test/reverse', 'https://nominatim.test/search', new Throttle($this->tempDir() . '/t', 0.0), 60);

        $places = $search->nearby(52.52, 13.405, 150);

        self::assertSame(['Späti Eck', 'Café Müller'], array_map(fn ($p) => $p->name, $places), 'Streets, unnamed buildings and far places are dropped');
        self::assertSame('https://photon.test/reverse', $http->requests[0]['url'], 'Reverse always uses Photon, even if search uses Nominatim');
        self::assertSame(0.15, $http->requests[0]['data']['radius']);
        self::assertSame('Kiosk', $places[0]->category);
    }

    public function testGeoapifyParsing(): void
    {
        $http = new FakeHttpClient($this->fixture('geoapify'));
        $places = (new Geoapify($http, new CacheRepository($this->migratedPdo()), 'https://geoapify.test/places', 'KEY', 60))->nearby(52.52, 13.405, 150);

        self::assertSame(['Späti Eck', 'Café Müller', 'Ohne OSM-Bezug'], array_map(fn ($p) => $p->name, $places));
        self::assertSame('node', $places[1]->osmType);
        self::assertSame(1001, $places[1]->osmId);
        self::assertSame('Café', $places[1]->category);
        self::assertSame('Rosenthaler Straße 12', $places[1]->address);
        self::assertNull($places[2]->osmType, 'No OSM reference without osm_type/osm_id');
        self::assertSame('circle:13.405000,52.520000,150', $http->requests[0]['data']['filter']);
        self::assertSame('KEY', $http->requests[0]['data']['apiKey']);
    }

    public function testNearbySearchUsesPrimaryWhenItAnswers(): void
    {
        $calls = [];
        $search = new NearbySearch([
            'overpass' => static function () use (&$calls): array {
                $calls[] = 'overpass';
                return [];
            },
            'photon' => static function () use (&$calls): array {
                $calls[] = 'photon';
                return [new Place('X', 1.0, 1.0)];
            },
        ], static function (): void {
        });

        $result = $search->find(1.0, 1.0, 100);
        self::assertSame('overpass', $result['source']);
        self::assertSame([], $result['places'], 'An empty Overpass answer is a real answer');
        self::assertNull($result['notice']);
        self::assertSame(['overpass'], $calls, 'Fallbacks are not asked');
    }

    public function testNearbySearchMergesAllFallbacks(): void
    {
        $logged = [];
        $search = new NearbySearch([
            'overpass' => static fn (): array => throw new OsmException('HTTP 502'),
            'geoapify' => static fn (): array => [
                new Place('Café Müller', 52.5201, 13.4052, 'node', 1001, 'Café', null, 15.0),
            ],
            'broken' => static fn (): array => throw new OsmException('HTTP 401'),
            'photon' => static fn (): array => [
                new Place('Café Müller', 52.5201, 13.4052, 'node', 1001, null, null, 15.0), // same OSM object
                new Place('Späti Eck', 52.52, 13.405, 'node', 1003, null, null, 2.0),
                new Place('café müller', 52.52012, 13.40522, null, null, null, null, 16.0), // same name, 2 m away
            ],
        ], function (string $m) use (&$logged): void {
            $logged[] = $m;
        });

        $result = $search->find(52.52, 13.405, 150);
        self::assertSame('geoapify+photon', $result['source']);
        self::assertSame(['Späti Eck', 'Café Müller'], array_map(fn (Place $p) => $p->name, $result['places']));
        self::assertSame('Café', $result['places'][1]->category, 'First source wins for duplicates');
        self::assertStringContainsString('Geoapify und Photon', (string) $result['notice']);
        self::assertSame(['Overpass: HTTP 502', 'Broken: HTTP 401'], $logged);
    }

    public function testNearbySearchReportsAllFailures(): void
    {
        $search = new NearbySearch([
            'overpass' => static fn (): array => throw new OsmException('a'),
            'photon' => static fn (): array => throw new OsmException('b'),
        ], static function (): void {
        });
        $this->expectExceptionMessage('Overpass: a | Photon: b');
        $search->find(1.0, 1.0, 100);
    }

    public function testDistance(): void
    {
        // Brandenburger Tor → Fernsehturm ≈ 2.2 km
        self::assertEqualsWithDelta(2205, Geo::distance(52.5163, 13.3777, 52.5208, 13.4094), 30);
        self::assertSame(0.0, Geo::distance(1.0, 1.0, 1.0, 1.0));
    }

    public function testCacheExpires(): void
    {
        $cache = new CacheRepository($this->migratedPdo());
        $cache->set('k', 'v', 10, 1000);
        self::assertSame('v', $cache->get('k', 1005));
        self::assertNull($cache->get('k', 1010));
    }
}
