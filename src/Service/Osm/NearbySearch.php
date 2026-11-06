<?php

declare(strict_types=1);

namespace App\Service\Osm;

use App\Model\Place;

/**
 * Nearby places: the first provider (Overpass) is authoritative. Only if it fails,
 * all fallback providers (Geoapify, Photon) are asked and their lists merged.
 */
final class NearbySearch
{
    /** Places closer than this with the same name count as duplicates (metres). */
    private const SAME_PLACE_DISTANCE = 40;
    /** Only the nearest places are returned; city centres can yield thousands. */
    public const MAX_PLACES = 100;

    /**
     * @param array<string, \Closure(float, float, int): list<Place>> $providers primary first
     * @param \Closure(string): void $log
     */
    public function __construct(
        private array $providers,
        private \Closure $log,
    ) {
    }

    /**
     * @return array{source: string, places: list<Place>, notice: ?string}
     * @throws OsmException when every provider failed (message lists all reasons)
     */
    public function find(float $lat, float $lon, int $radius): array
    {
        $errors = [];
        $primary = array_key_first($this->providers);
        $results = [];
        foreach ($this->providers as $name => $provider) {
            try {
                $results[$name] = $provider($lat, $lon, $radius);
            } catch (OsmException $e) {
                ($this->log)(ucfirst($name) . ': ' . $e->getMessage());
                $errors[] = ucfirst($name) . ': ' . $e->getMessage();
                continue;
            }
            if ($name === $primary) {
                return ['source' => $name, 'places' => array_slice($results[$name], 0, self::MAX_PLACES), 'notice' => null];
            }
        }
        if ($results === []) {
            throw new OsmException(implode(' | ', $errors));
        }

        $sources = array_map('ucfirst', array_keys($results));
        return [
            'source' => implode('+', array_keys($results)),
            'places' => array_slice(self::merge(...array_values($results)), 0, self::MAX_PLACES),
            'notice' => ucfirst((string) $primary) . ' ist nicht erreichbar, die Liste kommt von '
                . implode(' und ', $sources) . ' und ist evtl. unvollständig.',
        ];
    }

    /**
     * Merges lists, dropping duplicates (same OSM object, or same name close by).
     * Earlier lists win, so their richer data is kept.
     *
     * @param list<Place> ...$lists
     * @return list<Place> sorted by distance
     */
    public static function merge(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $place) {
                foreach ($merged as $existing) {
                    if (self::same($existing, $place)) {
                        continue 2;
                    }
                }
                $merged[] = $place;
            }
        }
        usort($merged, static fn (Place $a, Place $b): int => $a->distance <=> $b->distance);
        return $merged;
    }

    private static function same(Place $a, Place $b): bool
    {
        if ($a->osmType !== null && $a->osmType === $b->osmType && $a->osmId === $b->osmId) {
            return true;
        }
        return mb_strtolower(trim($a->name)) === mb_strtolower(trim($b->name))
            && Geo::distance($a->lat, $a->lon, $b->lat, $b->lon) <= self::SAME_PLACE_DISTANCE;
    }
}
