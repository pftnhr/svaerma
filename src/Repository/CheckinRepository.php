<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Checkin;
use App\Model\Visibility;
use PDO;

final class CheckinRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{lat: float, lon: float, place_name: string, osm_type: ?string, osm_id: ?int,
     *              checked_in_at: string, timezone: string, note: ?string, slug: string,
     *              source: string, external_id?: ?string, venue_ref?: ?string} $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO checkins (lat, lon, place_name, osm_type, osm_id, checked_in_at, timezone, note, slug,
                                   source, external_id, venue_ref, created_at, updated_at)
             VALUES (:lat, :lon, :place_name, :osm_type, :osm_id, :checked_in_at, :timezone, :note, :slug,
                     :source, :external_id, :venue_ref, :created_at, :updated_at)'
        );
        $stmt->execute([
            'lat' => $data['lat'],
            'lon' => $data['lon'],
            'place_name' => $data['place_name'],
            'osm_type' => $data['osm_type'],
            'osm_id' => $data['osm_id'],
            'checked_in_at' => $data['checked_in_at'],
            'timezone' => $data['timezone'],
            'note' => $data['note'],
            'slug' => $data['slug'],
            'source' => $data['source'],
            'external_id' => $data['external_id'] ?? null,
            'venue_ref' => $data['venue_ref'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Checkin
    {
        $stmt = $this->pdo->prepare('SELECT * FROM checkins WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? Checkin::fromRow($row) : null;
    }

    /**
     * Most recent check-in time (UTC) per OSM object.
     *
     * @param list<array{0: string, 1: int}> $refs [osm_type, osm_id] pairs
     * @return array<string, array{last: string, count: int}> keyed by "type/id"
     */
    public function visitsFor(array $refs): array
    {
        if ($refs === []) {
            return [];
        }
        $result = [];
        // Chunks keep each statement well below SQLite's parameter limit.
        foreach (array_chunk($refs, 200) as $chunk) {
            $conditions = [];
            $params = [];
            foreach ($chunk as [$type, $id]) {
                $conditions[] = '(osm_type = ? AND osm_id = ?)';
                $params[] = $type;
                $params[] = $id;
            }
            $stmt = $this->pdo->prepare(
                'SELECT osm_type, osm_id, MAX(checked_in_at) AS last, COUNT(*) AS cnt FROM checkins
                 WHERE ' . implode(' OR ', $conditions) . ' GROUP BY osm_type, osm_id'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $result[$row['osm_type'] . '/' . $row['osm_id']] = ['last' => (string) $row['last'], 'count' => (int) $row['cnt']];
            }
        }
        return $result;
    }

    /**
     * @return list<Checkin> newest first; with $visibility only what visitors may see
     */
    public function page(int $limit, int $offset, ?Visibility $visibility = null): array
    {
        if ($visibility?->limit !== null) {
            // Never page beyond the public window of the newest N check-ins.
            $limit = max(0, min($limit, $visibility->limit - $offset));
            if ($limit === 0) {
                return [];
            }
        }
        [$where, $params] = $this->visibilityFilter($visibility);
        $stmt = $this->pdo->prepare("SELECT * FROM checkins $where ORDER BY checked_in_at DESC, id DESC LIMIT ? OFFSET ?");
        $stmt->execute([...$params, $limit, $offset]);
        return array_map(Checkin::fromRow(...), $stmt->fetchAll());
    }

    public function count(?Visibility $visibility = null): int
    {
        [$where, $params] = $this->visibilityFilter($visibility);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM checkins $where");
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();
        return $visibility?->limit !== null ? min($count, $visibility->limit) : $count;
    }

    /**
     * Compact rows for the map, chronological.
     *
     * @return list<array{id: int, lat: float, lon: float, place_name: string, slug: string, checked_in_at: string}>
     */
    public function allForMap(?Visibility $visibility = null): array
    {
        [$where, $params] = $this->visibilityFilter($visibility);
        $sql = "SELECT id, lat, lon, place_name, slug, checked_in_at FROM checkins $where ORDER BY checked_in_at DESC, id DESC";
        if ($visibility?->limit !== null) {
            $sql .= ' LIMIT ' . $visibility->limit;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM ($sql) ORDER BY checked_in_at, id");
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'lat' => (float) $r['lat'],
            'lon' => (float) $r['lon'],
            'place_name' => (string) $r['place_name'],
            'slug' => (string) $r['slug'],
            'checked_in_at' => (string) $r['checked_in_at'],
        ], $stmt->fetchAll());
    }

    /** @return list<Checkin> All (visible) check-ins at the same OSM object, newest first */
    public function atPlace(string $osmType, int $osmId, ?Visibility $visibility = null): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM checkins WHERE osm_type = ? AND osm_id = ? ORDER BY checked_in_at DESC, id DESC');
        $stmt->execute([$osmType, $osmId]);
        $checkins = array_map(Checkin::fromRow(...), $stmt->fetchAll());
        return $visibility === null
            ? $checkins
            : array_values(array_filter($checkins, fn (Checkin $c): bool => $this->isVisible($c, $visibility)));
    }

    /** @return list<Checkin> All (visible) check-ins at the same external venue, newest first */
    public function atVenue(string $venueRef, ?Visibility $visibility = null): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM checkins WHERE venue_ref = ? ORDER BY checked_in_at DESC, id DESC');
        $stmt->execute([$venueRef]);
        $checkins = array_map(Checkin::fromRow(...), $stmt->fetchAll());
        return $visibility === null
            ? $checkins
            : array_values(array_filter($checkins, fn (Checkin $c): bool => $this->isVisible($c, $visibility)));
    }

    /**
     * Which of the given external IDs already exist for a source (import deduplication).
     *
     * @param list<string> $externalIds
     * @return array<string, true>
     */
    public function existingExternalIds(string $source, array $externalIds): array
    {
        $found = [];
        foreach (array_chunk($externalIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("SELECT external_id FROM checkins WHERE source = ? AND external_id IN ($marks)");
            $stmt->execute([$source, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $found[(string) $id] = true;
            }
        }
        return $found;
    }

    /**
     * Runs $work in one transaction (much faster for bulk inserts in SQLite).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Whether a visitor may see this check-in (delay, manual places, newest-N window). */
    public function isVisible(Checkin $checkin, Visibility $visibility): bool
    {
        $at = $checkin->checkedInAt->format('Y-m-d H:i:s');
        $cutoff = $visibility->cutoff();
        // The delay counts from the later of "happened" and "entered": back-dated
        // entries do not become public immediately.
        if ($at > $cutoff || $checkin->createdAt > $cutoff || (!$visibility->showManual && $checkin->osmType === null)) {
            return false;
        }
        if ($visibility->limit === null) {
            return true;
        }
        [$where, $params] = $this->visibilityFilter($visibility);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM checkins $where AND (checked_in_at > ? OR (checked_in_at = ? AND id > ?))");
        $stmt->execute([...$params, $at, $at, $checkin->id]);
        return (int) $stmt->fetchColumn() < $visibility->limit;
    }

    /** @return array{0: string, 1: list<string>} SQL WHERE clause and its parameters */
    private function visibilityFilter(?Visibility $visibility): array
    {
        if ($visibility === null) {
            return ['WHERE 1 = 1', []];
        }
        // Public after the delay, counted from the later of check-in time and entry time.
        $where = 'WHERE checked_in_at <= ? AND created_at <= ?';
        if (!$visibility->showManual) {
            $where .= ' AND osm_type IS NOT NULL';
        }
        $cutoff = $visibility->cutoff();
        return [$where, [$cutoff, $cutoff]];
    }

    /**
     * @param array{place_name: string, checked_in_at: string, timezone: string, note: ?string, slug: string,
     *              lat?: float, lon?: float, osm_type?: ?string, osm_id?: ?int} $data
     */
    public function update(int $id, array $data): void
    {
        $allowed = ['lat', 'lon', 'place_name', 'osm_type', 'osm_id', 'checked_in_at', 'timezone', 'note', 'slug'];
        $data = array_intersect_key($data, array_flip($allowed));
        $sets = array_map(static fn (string $col): string => "$col = :$col", array_keys($data));
        $stmt = $this->pdo->prepare('UPDATE checkins SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE id = :id');
        $stmt->execute($data + ['updated_at' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM checkins WHERE id = ?')->execute([$id]);
    }
}
