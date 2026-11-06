<?php

declare(strict_types=1);

namespace App\Service\Osm;

use App\Model\Place;
use App\Repository\CacheRepository;

/**
 * Search places by name: Photon (default) or Nominatim (throttled to 1 request/s).
 */
final class PlaceSearch
{
    private const TYPES = ['N' => 'node', 'W' => 'way', 'R' => 'relation', 'node' => 'node', 'way' => 'way', 'relation' => 'relation'];

    public function __construct(
        private HttpClient $http,
        private CacheRepository $cache,
        private string $provider,
        private string $photonUrl,
        private string $photonReverseUrl,
        private string $nominatimUrl,
        private Throttle $nominatimThrottle,
        private int $ttl,
    ) {
    }

    /** @return list<Place> */
    public function search(string $query, ?float $lat = null, ?float $lon = null, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $bias = $lat !== null && $lon !== null ? sprintf('%.3f,%.3f', $lat, $lon) : '-';
        $key = sprintf('search:%s:%s:%s:%d', $this->provider, mb_strtolower($query), $bias, $limit);

        $json = $this->cache->remember($key, $this->ttl, function () use ($query, $lat, $lon, $limit): string {
            if ($this->provider === 'nominatim') {
                $params = ['q' => $query, 'format' => 'jsonv2', 'limit' => $limit, 'accept-language' => 'de'];
                if ($lat !== null && $lon !== null) {
                    // Prefer results within ~20 km without excluding others.
                    $params['viewbox'] = sprintf('%.4F,%.4F,%.4F,%.4F', $lon - 0.2, $lat + 0.2, $lon + 0.2, $lat - 0.2);
                }
                $this->nominatimThrottle->wait();
                return $this->http->get($this->nominatimUrl, $params);
            }
            $params = ['q' => $query, 'limit' => $limit, 'lang' => 'de'];
            if ($lat !== null && $lon !== null) {
                $params += ['lat' => round($lat, 4), 'lon' => round($lon, 4)];
            }
            return $this->http->get($this->photonUrl, $params);
        });

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new OsmException('Die Ortssuche hat keine gültige Antwort geliefert.');
        }
        $places = $this->provider === 'nominatim' ? self::parseNominatim($data) : self::parsePhoton($data);
        if ($lat !== null && $lon !== null) {
            $places = array_map(static fn (Place $p): Place => $p->withDistance(Geo::distance($lat, $lon, $p->lat, $p->lon)), $places);
        }
        return $places;
    }

    /**
     * Places near a coordinate via Photon's reverse geocoding – the fallback when
     * Overpass is unavailable. Less complete than Overpass, but needs only a GET.
     *
     * @return list<Place> sorted by distance, within $radius metres
     */
    public function nearby(float $lat, float $lon, int $radius): array
    {
        $key = sprintf('photon-reverse:%.4f,%.4f,%d', $lat, $lon, $radius);
        $json = $this->cache->remember($key, $this->ttl, fn (): string => $this->http->get($this->photonReverseUrl, [
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
            'radius' => round($radius / 1000, 3), // km
            'limit' => 50,
            'lang' => 'de',
        ]));
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new OsmException('Photon hat keine gültige Antwort geliefert.');
        }

        $places = [];
        foreach (self::parsePhoton($data, onlyPlaces: true) as $place) {
            $distance = Geo::distance($lat, $lon, $place->lat, $place->lon);
            if ($distance <= $radius) {
                // Places without OSM reference cannot be deduplicated by it.
                $key = $place->osmType !== null && $place->osmId !== null ? $place->osmType . '/' . $place->osmId : 'x' . count($places);
                $places[$key] ??= $place->withDistance($distance);
            }
        }
        $places = array_values($places);
        usort($places, static fn (Place $a, Place $b): int => $a->distance <=> $b->distance);
        return $places;
    }

    /**
     * @param array<mixed> $data Photon GeoJSON
     * @param bool $onlyPlaces skip streets, houses etc. (see Categories::matches())
     * @return list<Place>
     */
    public static function parsePhoton(array $data, bool $onlyPlaces = false): array
    {
        $places = [];
        foreach ($data['features'] ?? [] as $f) {
            $props = $f['properties'] ?? [];
            $coords = $f['geometry']['coordinates'] ?? null;
            if (!isset($props['name']) || !is_array($coords) || count($coords) < 2) {
                continue;
            }
            if ($onlyPlaces && !Categories::matches([(string) ($props['osm_key'] ?? '') => (string) ($props['osm_value'] ?? '')])) {
                continue;
            }
            $type = self::TYPES[$props['osm_type'] ?? ''] ?? null;
            $address = implode(', ', array_filter([
                trim(($props['street'] ?? '') . ' ' . ($props['housenumber'] ?? '')),
                $props['city'] ?? ($props['county'] ?? null),
            ]));
            $places[] = new Place(
                name: (string) $props['name'],
                lat: (float) $coords[1],
                lon: (float) $coords[0],
                osmType: $type,
                osmId: $type !== null && isset($props['osm_id']) ? (int) $props['osm_id'] : null,
                category: isset($props['osm_key'], $props['osm_value'])
                    ? Categories::label([(string) $props['osm_key'] => (string) $props['osm_value']])
                    : null,
                address: $address !== '' ? $address : null,
            );
        }
        return $places;
    }

    /**
     * @param array<mixed> $data Nominatim jsonv2
     * @return list<Place>
     */
    public static function parseNominatim(array $data): array
    {
        $places = [];
        foreach ($data as $r) {
            if (!is_array($r) || !isset($r['lat'], $r['lon'])) {
                continue;
            }
            $display = (string) ($r['display_name'] ?? '');
            $name = (string) (($r['name'] ?? '') !== '' ? $r['name'] : strtok($display, ','));
            if ($name === '') {
                continue;
            }
            $type = self::TYPES[$r['osm_type'] ?? ''] ?? null;
            $places[] = new Place(
                name: $name,
                lat: (float) $r['lat'],
                lon: (float) $r['lon'],
                osmType: $type,
                osmId: $type !== null && isset($r['osm_id']) ? (int) $r['osm_id'] : null,
                category: isset($r['category'], $r['type'])
                    ? Categories::label([(string) $r['category'] => (string) $r['type']])
                    : null,
                address: $display !== '' ? $display : null,
            );
        }
        return $places;
    }
}
