<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Model\Checkin;

final class CheckinController extends Controller
{
    public const PER_PAGE = 50;

    /** @param array<string, string> $params */
    public function create(array $params): Response
    {
        return $this->render('checkin/create', [
            'radius' => $this->app->nearbyRadius(),
            'scripts' => ['checkin.js'],
        ]);
    }

    /** @param array<string, string> $params */
    public function store(array $params): Response
    {
        $result = $this->app->checkinService()->create($this->request->post, 'web');
        if (!$result instanceof Checkin) {
            foreach ($result as $error) {
                $this->flash('danger', $error);
            }
            return $this->redirect('/');
        }
        $this->flash('success', 'Eingecheckt: ' . $result->placeName);
        return $this->redirect($result->path());
    }

    /** @param array<string, string> $params */
    public function index(array $params): Response
    {
        $repo = $this->app->checkins();
        $visibility = $this->visibility();
        $total = $repo->count($visibility);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, (int) ($this->request->query['page'] ?? 1));
        if ($page > $pages) {
            return $this->redirect('/checkins?page=' . $pages);
        }

        // Group consecutive check-ins by their local calendar day. The list is sorted by
        // UTC, so after travelling across time zones a day may appear twice – that is
        // better than moving entries out of order into an earlier group.
        $days = [];
        $current = null;
        foreach ($repo->page(self::PER_PAGE, ($page - 1) * self::PER_PAGE, $visibility) as $checkin) {
            $day = $checkin->localTime()->format('Y-m-d');
            if ($day !== $current) {
                $days[] = [];
                $current = $day;
            }
            $days[array_key_last($days)][] = $checkin;
        }

        return $this->render('checkin/index', [
            'days' => $days,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'showNotes' => $visibility === null || $visibility->showNotes,
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params): Response
    {
        $checkin = $this->app->checkins()->find((int) $params['id']);
        $visibility = $this->visibility();
        if ($checkin === null || ($visibility !== null && !$this->app->checkins()->isVisible($checkin, $visibility))) {
            return $this->notFound();
        }
        $canonical = $checkin->canonicalRedirect($params['slug'] ?? '');
        if ($canonical !== null) {
            return $this->redirect($canonical, 301);
        }

        $visits = match (true) {
            $checkin->osmType !== null && $checkin->osmId !== null => $this->app->checkins()->atPlace($checkin->osmType, $checkin->osmId, $visibility),
            $checkin->venueRef !== null => $this->app->checkins()->atVenue($checkin->venueRef, $visibility),
            default => [$checkin],
        };

        return $this->render('checkin/show', [
            'checkin' => $checkin,
            'visits' => $visits,
            'showNotes' => $visibility === null || $visibility->showNotes,
            'leaflet' => true,
            'scripts' => ['map.js'],
            'tileUrl' => (string) $this->app->config->get('osm.tile_url'),
        ]);
    }

    /** @param array<string, string> $params */
    public function edit(array $params): Response
    {
        $checkin = $this->app->checkins()->find((int) $params['id']);
        if ($checkin === null) {
            return $this->notFound();
        }
        return $this->render('checkin/edit', [
            'checkin' => $checkin,
            'input' => [
                'place_name' => $checkin->placeName,
                'note' => (string) $checkin->note,
                'checked_in_at' => $checkin->localTime()->format('Y-m-d\TH:i'),
                'timezone' => $checkin->timezone,
            ],
            'errors' => [],
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): Response
    {
        $checkin = $this->app->checkins()->find((int) $params['id']);
        if ($checkin === null) {
            return $this->notFound();
        }
        $input = [
            'place_name' => $this->request->input('place_name'),
            'note' => $this->request->input('note'),
            'checked_in_at' => $this->request->input('checked_in_at'),
            'timezone' => $this->request->input('timezone'),
        ];
        $data = $input;
        // The form only has minute precision: an unchanged time keeps its seconds.
        if ($input['timezone'] === $checkin->timezone && $input['checked_in_at'] === $checkin->localTime()->format('Y-m-d\TH:i')) {
            $data['checked_in_at'] = $checkin->checkedInAt->format('Y-m-d\TH:i:s\Z');
        }
        $result = $this->app->checkinService()->update($checkin, $data);
        if (!$result instanceof Checkin) {
            return $this->render('checkin/edit', [
                'checkin' => $checkin,
                'input' => $input,
                'errors' => $result,
                'timezones' => \DateTimeZone::listIdentifiers(),
            ], 422);
        }
        $this->flash('success', 'Gespeichert.');
        return $this->redirect($result->path());
    }

    /** @param array<string, string> $params */
    public function delete(array $params): Response
    {
        $checkin = $this->app->checkins()->find((int) $params['id']);
        if ($checkin === null) {
            return $this->notFound();
        }
        $this->app->checkins()->delete($checkin->id);
        $this->flash('success', 'Check-in „' . $checkin->placeName . '“ gelöscht.');
        return $this->redirect('/checkins');
    }

    /** @param array<string, string> $params */
    public function map(array $params): Response
    {
        return $this->render('checkin/map', [
            'leaflet' => true,
            'scripts' => ['map.js'],
            'fullBleed' => true,
            'tileUrl' => (string) $this->app->config->get('osm.tile_url'),
        ]);
    }

    /**
     * All check-ins as compact rows for the map: [id, lat, lon, name, path, date].
     *
     * @param array<string, string> $params
     */
    public function mapData(array $params): Response
    {
        $rows = [];
        foreach ($this->app->checkins()->allForMap($this->visibility()) as $r) {
            $rows[] = [
                $r['id'],
                $r['lat'],
                $r['lon'],
                $r['place_name'],
                Checkin::pathFor($r['id'], $r['slug']),
                substr($r['checked_in_at'], 0, 10),
            ];
        }
        return Response::json($rows, 200, false)->withHeader('Cache-Control', 'private, no-cache');
    }
}
