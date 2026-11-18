<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Model\Place;
use App\Service\Osm\Geo;
use App\Service\Osm\OsmException;
use App\Service\Osm\Overpass;

/**
 * JSON endpoints for the check-in page (session-authenticated); the API reuses its helpers.
 */
class PlaceController extends Controller
{
    /** @param array<string, string> $params */
    public function nearby(array $params): Response
    {
        $coords = $this->coordinates();
        if ($coords === null) {
            return Response::json(['error' => 'Ungültige Koordinaten.'], 422);
        }
        $radius = max(Overpass::MIN_RADIUS, min(Overpass::MAX_RADIUS, (int) ($this->request->query['radius'] ?? $this->app->nearbyRadius())));
        try {
            $result = $this->nearbyWithOwn($coords[0], $coords[1], $radius);
        } catch (OsmException $e) {
            return Response::json([
                'error' => 'Gerade ist kein Ortsdienst erreichbar. Nutze die Suche oder einen eigenen Ort.',
                'detail' => $e->getMessage(),
            ], 502);
        }
        return Response::json($result);
    }

    /**
     * Nearby places from the map services plus the user's own places, with visit info.
     * Places already visited come first, each group sorted by distance. If every map
     * service fails, the own places alone are returned (if there are any).
     *
     * @return array{source: string, notice: ?string, places: list<array<string, mixed>>}
     * @throws OsmException when the map services fail and there is no own place nearby
     */
    protected function nearbyWithOwn(float $lat, float $lon, int $radius): array
    {
        $own = $this->app->ownPlaces()->near($lat, $lon, $radius);
        try {
            $result = $this->app->nearbySearch()->find($lat, $lon, $radius);
        } catch (OsmException $e) {
            if ($own === []) {
                throw $e;
            }
            $result = [
                'source' => 'own',
                'places' => [],
                'notice' => 'Gerade ist kein Ortsdienst erreichbar, die Liste zeigt nur deine eigenen Orte.',
            ];
        }

        $places = self::visitedFirst($this->withVisits([...$own, ...$result['places']]));
        return ['source' => $result['source'], 'notice' => $result['notice'], 'places' => $places];
    }

    /**
     * Visited places first, then the rest; each group nearest first (stable otherwise).
     *
     * @param list<array<string, mixed>> $places with "visits" and "distance"
     * @return list<array<string, mixed>>
     */
    public static function visitedFirst(array $places): array
    {
        usort($places, static fn (array $a, array $b): int => [$a['visits'] === 0, $a['distance'] ?? PHP_INT_MAX]
            <=> [$b['visits'] === 0, $b['distance'] ?? PHP_INT_MAX]);
        return $places;
    }

    /** @param array<string, string> $params */
    public function search(array $params): Response
    {
        $coords = $this->coordinates();
        try {
            $places = $this->app->placeSearch()->search($this->request->input('q'), $coords[0] ?? null, $coords[1] ?? null);
        } catch (OsmException $e) {
            $this->app->log('Search: ' . $e->getMessage());
            return Response::json(['error' => 'Die Ortssuche ist gerade nicht erreichbar.', 'detail' => $e->getMessage()], 502);
        }
        return Response::json(['places' => $this->withVisits($places)]);
    }

    /** @return array{0: float, 1: float}|null */
    protected function coordinates(): ?array
    {
        $lat = filter_var($this->request->query['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($this->request->query['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false || !Geo::validCoordinates($lat, $lon)) {
            return null;
        }
        return [$lat, $lon];
    }

    /**
     * Adds "last visited" info to each place (by OSM object, for own places by venue reference).
     *
     * @param list<Place> $places
     * @return list<array<string, mixed>>
     */
    protected function withVisits(array $places): array
    {
        $refs = [];
        $venueRefs = [];
        foreach ($places as $p) {
            if ($p->osmType !== null && $p->osmId !== null) {
                $refs[] = [$p->osmType, $p->osmId];
            } elseif ($p->venueRef !== null) {
                $venueRefs[] = $p->venueRef;
            }
        }
        $repo = $this->app->checkins();
        $visits = $repo->visitsFor($refs);
        $venueVisits = $repo->visitsForVenues($venueRefs);
        $tz = new \DateTimeZone((string) $this->app->config->get('timezone'));

        return array_map(static function (Place $p) use ($visits, $venueVisits, $tz): array {
            $data = $p->jsonSerialize();
            $visit = $p->osmType !== null
                ? $visits[$p->osmType . '/' . $p->osmId] ?? null
                : $venueVisits[(string) $p->venueRef] ?? null;
            $data['visits'] = $visit['count'] ?? 0;
            $data['last_visit'] = $visit !== null
                ? (new \DateTimeImmutable($visit['last'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y')
                : null;
            return $data;
        }, $places);
    }
}
