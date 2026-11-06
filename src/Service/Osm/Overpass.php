<?php

declare(strict_types=1);

namespace App\Service\Osm;

use App\Model\Place;
use App\Repository\CacheRepository;

/**
 * Finds named places around a coordinate via the Overpass API.
 *
 * The query asks only for "named objects around X" (cheap for the server);
 * filtering by category happens here in PHP. Endpoints are tried in order.
 */
final class Overpass
{
    public const MIN_RADIUS = 10;
    public const MAX_RADIUS = 1000;
    private const QUERY_TIMEOUT = 10;
    /** After repeated total failures Overpass is skipped for this long (seconds). */
    public const COOLDOWN = 300;
    public const COOLDOWN_KEY = 'overpass:cooldown';
    /** Total failures within COOLDOWN seconds that trigger the cooldown. A single
     *  failure (e.g. a timeout on a large radius) does not block everyone else. */
    public const COOLDOWN_AFTER = 3;
    private const FAILURES_KEY = 'overpass:failures';

    /**
     * @param list<array{url: string, label: string, headers?: array<string, string>}> $endpoints
     *        label is used in error messages, so it must not contain secrets
     */
    public function __construct(
        private HttpClient $http,
        private CacheRepository $cache,
        private array $endpoints,
        private int $ttl,
    ) {
    }

    /** @return list<Place> sorted by distance */
    public function nearby(float $lat, float $lon, int $radius): array
    {
        $radius = max(self::MIN_RADIUS, min(self::MAX_RADIUS, $radius));
        // ~11 m grid: neighbouring requests share a cache entry.
        $key = sprintf('overpass:v2:%.4f,%.4f,%d', $lat, $lon, $radius);

        $json = $this->cache->get($key);
        if ($json === null) {
            $cooldown = $this->cache->get(self::COOLDOWN_KEY);
            if ($cooldown !== null) {
                throw new OsmException('kurz zuvor nicht erreichbar (' . $cooldown . '), nächster Versuch in höchstens ' . (self::COOLDOWN / 60) . ' min');
            }
            try {
                $json = $this->fetch(self::query($lat, $lon, $radius));
            } catch (OsmException $e) {
                $failures = (int) ($this->cache->get(self::FAILURES_KEY) ?? 0) + 1;
                $this->cache->set(self::FAILURES_KEY, (string) $failures, self::COOLDOWN);
                if ($failures >= self::COOLDOWN_AFTER) {
                    $this->cache->set(self::COOLDOWN_KEY, gmdate('H:i') . ' UTC', self::COOLDOWN);
                    $this->cache->set(self::FAILURES_KEY, '0', 1);
                }
                throw $e;
            }
            $this->cache->set(self::FAILURES_KEY, '0', 1);
            // Only valid, complete answers are cached.
            $this->cache->set($key, $json, $this->ttl);
        }

        /** @var array{elements: array<mixed>} $data */
        $data = json_decode($json, true);
        return self::parse($data['elements'], $lat, $lon);
    }

    /**
     * Sends a query to the first endpoint that answers properly.
     *
     * @throws OsmException with the reason of every endpoint that failed
     */
    public function fetch(string $query): string
    {
        $errors = [];
        foreach ($this->endpoints as $endpoint) {
            try {
                // GET: the query is short and GET passes every proxy we met.
                $json = $this->http->get($endpoint['url'], ['data' => $query], $endpoint['headers'] ?? []);
                self::validate($json);
                return $json;
            } catch (OsmException $e) {
                $errors[] = $endpoint['label'] . ': ' . $e->getMessage();
            }
        }
        throw new OsmException(implode(' | ', $errors) ?: 'Kein Overpass-Endpunkt konfiguriert.');
    }

    public static function query(float $lat, float $lon, int $radius): string
    {
        return sprintf(
            "[out:json][timeout:%d];\nnwr(around:%d,%.6F,%.6F)[\"name\"];\nout tags center qt;",
            self::QUERY_TIMEOUT,
            $radius,
            $lat,
            $lon,
        );
    }

    /**
     * Overpass reports timeouts and memory errors as HTTP 200 with a "remark".
     */
    private static function validate(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements'])) {
            throw new OsmException('ungültige Antwort');
        }
        if (isset($data['remark']) && is_string($data['remark']) && str_contains($data['remark'], 'error')) {
            throw new OsmException($data['remark']);
        }
    }

    /**
     * @param array<mixed> $elements Overpass "elements"
     * @return list<Place>
     */
    public static function parse(array $elements, float $lat, float $lon): array
    {
        $places = [];
        foreach ($elements as $el) {
            if (!is_array($el) || !isset($el['type'], $el['id'], $el['tags']['name']) || !is_array($el['tags'])) {
                continue;
            }
            /** @var array<string, string> $tags */
            $tags = $el['tags'];
            if (!Categories::matches($tags)) {
                continue;
            }
            $pLat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $pLon = $el['lon'] ?? $el['center']['lon'] ?? null;
            if (!is_numeric($pLat) || !is_numeric($pLon)) {
                continue;
            }
            $key = $el['type'] . '/' . $el['id'];
            $places[$key] ??= new Place(
                name: (string) $tags['name'],
                lat: (float) $pLat,
                lon: (float) $pLon,
                osmType: (string) $el['type'],
                osmId: (int) $el['id'],
                category: Categories::label($tags),
                address: self::address($tags),
                distance: Geo::distance($lat, $lon, (float) $pLat, (float) $pLon),
            );
        }
        $places = array_values($places);
        usort($places, static fn (Place $a, Place $b): int => $a->distance <=> $b->distance);
        return $places;
    }

    /** @param array<string, string> $tags */
    private static function address(array $tags): ?string
    {
        $street = trim(($tags['addr:street'] ?? '') . ' ' . ($tags['addr:housenumber'] ?? ''));
        return $street !== '' ? $street : null;
    }
}
