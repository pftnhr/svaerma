<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Http\Response;
use App\Model\Checkin;
use App\Service\Osm\OsmException;
use App\Service\Osm\Overpass;

/**
 * REST API v1 (bearer token, see App::dispatchApi()). Made for iOS Shortcuts:
 * accepts JSON or form bodies, answers with flat, predictable JSON.
 */
final class ApiController extends PlaceController
{
    private const MAX_LIMIT = 200;

    /** @param array<string, string> $params */
    public function listCheckins(array $params): Response
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($this->request->query['limit'] ?? 50)));
        $offset = max(0, (int) ($this->request->query['offset'] ?? 0));
        $repo = $this->app->checkins();
        $total = $repo->count();

        return Response::json([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'checkins' => array_map($this->checkinJson(...), $repo->page($limit, $offset)),
            'next' => $offset + $limit < $total ? $this->url(sprintf('/api/v1/checkins?limit=%d&offset=%d', $limit, $offset + $limit)) : null,
        ]);
    }

    /** @param array<string, string> $params */
    public function showCheckin(array $params): Response
    {
        $checkin = $this->app->checkins()->find((int) $params['id']);
        return $checkin === null
            ? Response::json(['error' => 'Check-in nicht gefunden.'], 404)
            : Response::json($this->checkinJson($checkin));
    }

    /**
     * Body (JSON or form): lat, lon, place_name, osm_type, osm_id, note, timezone, checked_in_at.
     * Alternatively "place": one object from /api/v1/places/nearby (as object or as its JSON text), plus "note".
     *
     * @param array<string, string> $params
     */
    public function createCheckin(array $params): Response
    {
        $input = $this->request->json() ?? $this->request->post;
        if (array_key_exists('place', $input)) {
            $place = $input['place'];
            // Shortcuts cannot put a variable into a field of type Dictionary, only into Text.
            // A dictionary variable in a Text field arrives as its JSON text, so decode it.
            if (is_string($place)) {
                $place = json_decode($place, true);
            }
            if (!is_array($place) || $place === []) {
                return $this->invalid(['Feld place ist leer oder kein Ort aus /api/v1/places/nearby.']);
            }
            $input += [
                'lat' => $place['lat'] ?? null,
                'lon' => $place['lon'] ?? null,
                'place_name' => $place['name'] ?? null,
                'osm_type' => $place['osm_type'] ?? null,
                'osm_id' => $place['osm_id'] ?? null,
            ];
        }
        unset($input['place']);

        $result = $this->app->checkinService()->create($input, 'api');
        if (!$result instanceof Checkin) {
            return $this->invalid($result);
        }
        return Response::json($this->checkinJson($result), 201)->withHeader('Location', $this->url('/api/v1/checkins/' . $result->id));
    }

    /**
     * 422 that also says what arrived, so a Shortcuts notification shows why a field is missing.
     *
     * @param list<string> $errors
     */
    private function invalid(array $errors): Response
    {
        $received = $this->receivedSummary();
        return Response::json([
            'error' => implode(' ', $errors) . ' Empfangen: ' . $received,
            'errors' => $errors,
            'received' => $received,
        ], 422);
    }

    /** Field names and types of the request body, never their values. */
    private function receivedSummary(): string
    {
        $json = $this->request->json();
        if ($json === null) {
            if ($this->request->post !== []) {
                return 'Formular mit ' . implode(', ', array_map('strval', array_keys($this->request->post)));
            }
            $length = strlen($this->request->body);
            return $length === 0
                ? 'leerer Body'
                : sprintf('kein gültiges JSON-Objekt (%d Bytes, Content-Type: %s)', $length, $this->request->header('content-type') ?? 'keiner');
        }
        if ($json === []) {
            return 'leeres JSON-Objekt';
        }
        $parts = [];
        foreach ($json as $key => $value) {
            $parts[] = $key . ' (' . self::describe($value) . ')';
        }
        return implode(', ', $parts);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'leer',
            is_bool($value) => 'Wahrheitswert',
            is_int($value), is_float($value) => 'Zahl',
            $value === [] => 'leeres Objekt',
            is_array($value) => array_is_list($value) ? sprintf('Liste, %d Einträge', count($value)) : 'Objekt mit ' . implode(', ', array_map('strval', array_keys($value))),
            is_array(json_decode((string) $value, true)) => sprintf('Text mit JSON, %d Zeichen', mb_strlen((string) $value)),
            default => sprintf('Text, %d Zeichen', mb_strlen((string) $value)),
        };
    }

    /**
     * Nearby places. Besides the list, "choices" maps a readable label to each place –
     * ideal for "Choose from List" in Shortcuts, whose result can be posted back as "place".
     *
     * @param array<string, string> $params
     */
    public function nearbyPlaces(array $params): Response
    {
        $coords = $this->coordinates();
        if ($coords === null) {
            return Response::json(['error' => 'Parameter lat und lon fehlen oder sind ungültig.'], 422);
        }
        // Protects the server's standing with Overpass/Nominatim even if a token leaks.
        if (!$this->app->rateLimit('nearby:' . $this->app->apiTokenId(), App::API_NEARBY_PER_MINUTE)) {
            return Response::json(['error' => 'Zu viele Anfragen, bitte kurz warten.'], 429)->withHeader('Retry-After', '60');
        }
        $radius = max(Overpass::MIN_RADIUS, min(Overpass::MAX_RADIUS, (int) ($this->request->query['radius'] ?? $this->app->nearbyRadius())));
        try {
            $result = $this->app->nearbySearch()->find($coords[0], $coords[1], $radius);
        } catch (OsmException $e) {
            return Response::json(['error' => 'Gerade ist kein Ortsdienst erreichbar.', 'detail' => $e->getMessage()], 502);
        }

        $places = $this->withVisits($result['places']);
        $choices = [];
        foreach ($places as $i => $place) {
            $label = self::label($place);
            $places[$i]['label'] = $label;
            $unique = $label;
            for ($n = 2; isset($choices[$unique]); $n++) {
                $unique = "$label ($n)";
            }
            $choices[$unique] = $place + ['label' => $unique];
        }

        return Response::json([
            'source' => $result['source'],
            'notice' => $result['notice'],
            'radius' => $radius,
            'places' => $places,
            'choices' => (object) $choices,
        ]);
    }

    /** @param array<string, mixed> $place */
    private static function label(array $place): string
    {
        $parts = [(string) $place['name']];
        if (!empty($place['category'])) {
            $parts[] = (string) $place['category'];
        }
        if (isset($place['distance'])) {
            $parts[] = $place['distance'] . ' m';
        }
        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    private function checkinJson(Checkin $c): array
    {
        return [
            'id' => $c->id,
            'place_name' => $c->placeName,
            'lat' => $c->lat,
            'lon' => $c->lon,
            'osm_type' => $c->osmType,
            'osm_id' => $c->osmId,
            'osm_url' => $c->osmUrl(),
            'checked_in_at' => $c->checkedInAt->format('Y-m-d\TH:i:s\Z'),
            'local_time' => $c->localTime()->format(DATE_ATOM),
            'timezone' => $c->timezone,
            'note' => $c->note,
            'source' => $c->source,
            'url' => $this->url($c->path()),
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->app->config->get('base_url'), '/') . $path;
    }
}
