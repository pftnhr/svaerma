<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CheckinRepository;

/**
 * Imports checkins*.json from the Foursquare/Swarm data export.
 *
 * Expected shape: {"count": n, "items": [{"id", "createdAt" (UTC), "timeZoneOffset" (minutes),
 * "venue": {"id", "name"}, "lat", "lng", "shout"?}, …]}. Deduplicates by Swarm check-in id.
 */
final class SwarmImporter
{
    public const SOURCE = 'swarm';

    public function __construct(
        private CheckinRepository $checkins,
        private CheckinService $service,
        private string $defaultTimezone,
    ) {
    }

    /**
     * @param array<string, string> $files file name => JSON content
     * @return array{total: int, imported: int, duplicates: int, skipped: list<array{file: string, id: string, reason: string}>, dry_run: bool}
     */
    public function import(array $files, bool $dryRun): array
    {
        $report = ['total' => 0, 'imported' => 0, 'duplicates' => 0, 'skipped' => [], 'dry_run' => $dryRun];

        /** @var list<array{file: string, id: string, data: array<string, mixed>}> $rows */
        $rows = [];
        foreach ($files as $file => $json) {
            $items = $this->items($json);
            if ($items === null) {
                $report['skipped'][] = ['file' => $file, 'id' => '–', 'reason' => 'Keine gültige Swarm-Exportdatei (erwartet: {"items": [...]}).'];
                continue;
            }
            foreach ($items as $i => $item) {
                $report['total']++;
                $hasId = is_array($item) && isset($item['id']) && is_scalar($item['id']) && (string) $item['id'] !== '';
                $mapped = is_array($item) ? $this->map($item) : 'Eintrag ist kein Objekt.';
                if (is_string($mapped)) {
                    $report['skipped'][] = ['file' => $file, 'id' => $hasId ? (string) $item['id'] : '#' . ($i + 1), 'reason' => $mapped];
                    continue;
                }
                // Without a Swarm id, derive a stable one from the content: positions like
                // "#1" collide across files and would block other entries in later imports.
                $id = $hasId ? (string) $item['id'] : self::contentId($mapped);
                $rows[] = ['file' => $file, 'id' => $id, 'data' => $mapped];
            }
        }

        $existing = $this->checkins->existingExternalIds(self::SOURCE, array_column($rows, 'id'));
        $seen = [];
        $toInsert = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['id']]) || isset($seen[$row['id']])) {
                $report['duplicates']++;
                continue;
            }
            $seen[$row['id']] = true;
            $valid = $this->service->validate($row['data']);
            if ($valid['data'] === null) {
                $report['skipped'][] = ['file' => $row['file'], 'id' => $row['id'], 'reason' => implode(' ', $valid['errors'])];
                continue;
            }
            $toInsert[] = $valid['data'] + [
                'source' => self::SOURCE,
                'external_id' => $row['id'],
                'venue_ref' => $row['data']['venue_ref'],
            ];
        }

        if (!$dryRun && $toInsert !== []) {
            $this->checkins->transaction(function () use ($toInsert): void {
                foreach ($toInsert as $data) {
                    $this->checkins->create($data);
                }
            });
        }
        $report['imported'] = count($toInsert);
        return $report;
    }

    /** @param array<string, mixed> $mapped */
    private static function contentId(array $mapped): string
    {
        return 'sha1:' . sha1(implode('|', [
            (string) $mapped['checked_in_at'],
            (string) $mapped['place_name'],
            sprintf('%.6F', (float) $mapped['lat']),
            sprintf('%.6F', (float) $mapped['lon']),
        ]));
    }

    /** @return list<mixed>|null */
    private function items(string $json): ?array
    {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
            return array_values($data['items']);
        }
        // Some export variants are a bare list of check-ins.
        return is_array($data) && array_is_list($data) ? $data : null;
    }

    /**
     * Swarm item → CheckinService input, or the reason why it cannot be imported.
     *
     * @param array<mixed> $item
     * @return array<string, mixed>|string
     */
    public function map(array $item): array|string
    {
        if (($item['type'] ?? 'checkin') !== 'checkin') {
            return 'Kein Check-in (Typ „' . (string) $item['type'] . '“).';
        }
        $venue = is_array($item['venue'] ?? null) ? $item['venue'] : [];
        $name = trim((string) ($venue['name'] ?? ''));
        if ($name === '') {
            return 'Kein Ort angegeben.';
        }
        $lat = $item['lat'] ?? $venue['location']['lat'] ?? null;
        $lon = $item['lng'] ?? $venue['location']['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return 'Keine Koordinaten.';
        }

        $createdAt = $item['createdAt'] ?? null;
        $time = match (true) {
            is_int($createdAt), is_string($createdAt) && ctype_digit($createdAt) => (string) $createdAt,
            is_string($createdAt) && $createdAt !== '' => $createdAt . (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $createdAt) ? '' : ' UTC'),
            default => null,
        };
        if ($time === null) {
            return 'Kein Zeitpunkt.';
        }

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lon,
            'place_name' => $name,
            'note' => isset($item['shout']) && is_string($item['shout']) ? $item['shout'] : null,
            'checked_in_at' => $time,
            'timezone' => isset($item['timeZoneOffset']) && is_numeric($item['timeZoneOffset'])
                ? $this->timezoneFor((int) $item['timeZoneOffset'], $time)
                : $this->defaultTimezone,
            'venue_ref' => isset($venue['id']) && is_scalar($venue['id']) ? 'foursquare:' . $venue['id'] : null,
        ];
    }

    /**
     * Swarm only stores the UTC offset. Use the default zone when its offset matched at
     * that moment (keeps "Europe/Berlin"), otherwise a fixed offset like "+05:30".
     */
    public function timezoneFor(int $offsetMinutes, string $time): string
    {
        try {
            $utc = ctype_digit($time) ? new \DateTimeImmutable('@' . $time) : new \DateTimeImmutable($time);
            $default = new \DateTimeZone($this->defaultTimezone);
            if ($default->getOffset($utc) === $offsetMinutes * 60) {
                return $this->defaultTimezone;
            }
        } catch (\Exception) {
            // fall through to the fixed offset
        }
        $sign = $offsetMinutes < 0 ? '-' : '+';
        $abs = abs($offsetMinutes);
        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60);
    }
}
