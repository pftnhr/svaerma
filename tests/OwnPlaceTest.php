<?php

declare(strict_types=1);

namespace App\Tests;

use App\Controller\PlaceController;
use App\Model\Checkin;
use App\Repository\CheckinRepository;
use App\Repository\OwnPlaceRepository;
use App\Service\CheckinService;

final class OwnPlaceTest extends TestCase
{
    private CheckinRepository $checkins;
    private OwnPlaceRepository $places;
    private CheckinService $service;

    protected function setUp(): void
    {
        $pdo = $this->migratedPdo();
        $this->checkins = new CheckinRepository($pdo);
        $this->places = new OwnPlaceRepository($pdo);
        $this->service = new CheckinService($this->checkins, 'Europe/Berlin', $this->places);
    }

    public function testNewPlaceIsRememberedAndReusedNearby(): void
    {
        $first = $this->service->create(['lat' => 52.52, 'lon' => 13.405, 'place_name' => 'Office', 'new_place' => '1'], 'web');
        self::assertInstanceOf(Checkin::class, $first);
        self::assertSame('own:1', $first->venueRef);

        // Same name ~30 m away (GPS jitter), different case: same place.
        $again = $this->service->create(['lat' => 52.5203, 'lon' => 13.405, 'place_name' => 'office', 'new_place' => '1'], 'web');
        self::assertInstanceOf(Checkin::class, $again);
        self::assertSame('own:1', $again->venueRef);

        // Same name far away: a different place.
        $elsewhere = $this->service->create(['lat' => 48.13, 'lon' => 11.58, 'place_name' => 'Office', 'new_place' => '1'], 'web');
        self::assertInstanceOf(Checkin::class, $elsewhere);
        self::assertSame('own:2', $elsewhere->venueRef);

        self::assertCount(2, $this->checkins->atVenue('own:1'));
    }

    public function testCheckinAtExistingOwnPlace(): void
    {
        $ref = $this->places->findOrCreate('Office', 52.52, 13.405);
        $checkin = $this->service->create(['lat' => 52.52, 'lon' => 13.405, 'place_name' => 'Office', 'venue_ref' => $ref], 'api');
        self::assertInstanceOf(Checkin::class, $checkin);
        self::assertSame($ref, $checkin->venueRef);

        self::assertSame(['Unbekannter eigener Ort.'], $this->service->create(['lat' => 1, 'lon' => 2, 'place_name' => 'X', 'venue_ref' => 'own:99'], 'web'));
        self::assertSame(['Unbekannter eigener Ort.'], $this->service->create(['lat' => 1, 'lon' => 2, 'place_name' => 'X', 'venue_ref' => 'foursquare:abc'], 'web'));
    }

    public function testOsmPlaceIsNeverStoredAsOwnPlace(): void
    {
        $checkin = $this->service->create(['lat' => 52.52, 'lon' => 13.405, 'place_name' => 'Café', 'osm_type' => 'node', 'osm_id' => 5, 'new_place' => '1'], 'web');
        self::assertInstanceOf(Checkin::class, $checkin);
        self::assertNull($checkin->venueRef);
        self::assertSame([], $this->places->near(52.52, 13.405, 1000));
    }

    public function testWithoutNewPlaceFlagNothingIsRemembered(): void
    {
        $checkin = $this->service->create(['lat' => 52.52, 'lon' => 13.405, 'place_name' => 'Parkbank'], 'web');
        self::assertInstanceOf(Checkin::class, $checkin);
        self::assertNull($checkin->venueRef);
        self::assertSame([], $this->places->near(52.52, 13.405, 1000));
    }

    public function testNearFiltersByRadiusAndSortsByDistance(): void
    {
        $this->places->findOrCreate('Weit', 52.5220, 13.405);   // ~220 m
        $this->places->findOrCreate('Nah', 52.5205, 13.405);    // ~55 m
        $this->places->findOrCreate('Mitte', 52.5212, 13.405);  // ~130 m

        $near = $this->places->near(52.52, 13.405, 150);
        self::assertSame(['Nah', 'Mitte'], array_map(fn ($p) => $p->name, $near));
        self::assertSame('Eigener Ort', $near[0]->category);
        self::assertSame(56, $near[0]->jsonSerialize()['distance']);
        self::assertSame('own:2', $near[0]->jsonSerialize()['venue_ref']);
    }

    public function testVisitsForVenues(): void
    {
        $ref = $this->places->findOrCreate('Office', 52.52, 13.405);
        foreach (['2026-01-01 08:00:00', '2026-03-01 08:00:00'] as $time) {
            $this->service->create(['lat' => 52.52, 'lon' => 13.405, 'place_name' => 'Office', 'venue_ref' => $ref, 'checked_in_at' => $time . 'Z'], 'web');
        }
        $visits = $this->checkins->visitsForVenues([$ref, 'own:42']);
        self::assertSame(['own:1' => ['last' => '2026-03-01 08:00:00', 'count' => 2]], $visits);
    }

    public function testVisitedPlacesComeFirst(): void
    {
        $sorted = PlaceController::visitedFirst([
            ['name' => 'A', 'distance' => 5, 'visits' => 0],
            ['name' => 'B', 'distance' => 140, 'visits' => 2],
            ['name' => 'C', 'distance' => 30, 'visits' => 0],
            ['name' => 'D', 'distance' => 60, 'visits' => 1],
        ]);
        self::assertSame(['D', 'B', 'A', 'C'], array_column($sorted, 'name'));
    }
}
