<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Place;
use App\Service\Osm\Geo;
use PDO;

/**
 * Places the user created themselves. Referenced from check-ins as "own:<id>".
 */
final class OwnPlaceRepository
{
    public const REF_PREFIX = 'own:';
    public const CATEGORY = 'Eigener Ort';
    /** A new place with the same name this close to an existing one reuses it (metres). */
    public const SAME_PLACE_DISTANCE = 100;

    public function __construct(private PDO $pdo)
    {
    }

    public static function ref(int $id): string
    {
        return self::REF_PREFIX . $id;
    }

    /** Id from an "own:<id>" reference, null for anything else. */
    public static function idFromRef(string $ref): ?int
    {
        return preg_match('/^own:([1-9]\d{0,18})$/', $ref, $m) ? (int) $m[1] : null;
    }

    public function find(int $id): ?Place
    {
        $stmt = $this->pdo->prepare('SELECT * FROM own_places WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::place($row) : null;
    }

    /**
     * Reuses a place with the same name nearby, otherwise creates one.
     *
     * @return string the "own:<id>" reference
     */
    public function findOrCreate(string $name, float $lat, float $lon): string
    {
        foreach ($this->near($lat, $lon, self::SAME_PLACE_DISTANCE) as $place) {
            if (mb_strtolower(trim($place->name)) === mb_strtolower(trim($name))) {
                return (string) $place->venueRef;
            }
        }
        $this->pdo->prepare('INSERT INTO own_places (name, lat, lon, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, $lat, $lon, gmdate('Y-m-d H:i:s')]);
        return self::ref((int) $this->pdo->lastInsertId());
    }

    /** @return list<Place> places within $radius metres, nearest first, with distance */
    public function near(float $lat, float $lon, int $radius): array
    {
        // Bounding box first (cheap, indexed), exact distance afterwards.
        $dLat = $radius / 111_320;
        $dLon = $radius / (111_320 * max(0.01, cos(deg2rad($lat))));
        $stmt = $this->pdo->prepare('SELECT * FROM own_places WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?');
        $stmt->execute([$lat - $dLat, $lat + $dLat, $lon - $dLon, $lon + $dLon]);

        $places = [];
        foreach ($stmt->fetchAll() as $row) {
            $place = self::place($row);
            $distance = Geo::distance($lat, $lon, $place->lat, $place->lon);
            if ($distance <= $radius) {
                $places[] = $place->withDistance($distance);
            }
        }
        usort($places, static fn (Place $a, Place $b): int => $a->distance <=> $b->distance);
        return $places;
    }

    /** @param array<string, mixed> $row */
    private static function place(array $row): Place
    {
        return new Place(
            name: (string) $row['name'],
            lat: (float) $row['lat'],
            lon: (float) $row['lon'],
            category: self::CATEGORY,
            venueRef: self::ref((int) $row['id']),
        );
    }
}
