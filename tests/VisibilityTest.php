<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\Checkin;
use App\Model\Visibility;
use App\Repository\CheckinRepository;
use App\Service\CheckinService;

final class VisibilityTest extends TestCase
{
    private CheckinRepository $repo;
    private \PDO $pdo;
    /** @var array<string, Checkin> */
    private array $c = [];
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = $this->migratedPdo();
        $this->repo = new CheckinRepository($this->pdo);
        $service = new CheckinService($this->repo, 'Europe/Berlin');
        $this->now = new \DateTimeImmutable('2026-10-29 12:00:00', new \DateTimeZone('UTC'));
        $rows = [
            'old' => ['2026-10-27T10:00:00Z', 'node'],
            'manual' => ['2026-10-28T10:00:00Z', null],
            'yesterday' => ['2026-10-28T20:00:00Z', 'way'],
            'recent' => ['2026-10-29T11:00:00Z', 'node'], // 1 h ago
        ];
        foreach ($rows as $name => [$time, $type]) {
            $checkin = $service->create([
                'lat' => 1, 'lon' => 1, 'place_name' => $name, 'checked_in_at' => $time,
                'osm_type' => $type ?? '', 'osm_id' => $type !== null ? 1 : '',
            ], 'web');
            self::assertInstanceOf(Checkin::class, $checkin);
            // Entered when it happened (the delay also counts from the entry time).
            $this->pdo->prepare('UPDATE checkins SET created_at = checked_in_at WHERE id = ?')->execute([$checkin->id]);
            $this->c[$name] = $this->repo->find($checkin->id) ?? throw new \RuntimeException('missing');
        }
    }

    public function testBackdatedEntriesWaitForTheDelayToo(): void
    {
        // Entered 30 min ago, but dated two days back: not public before the delay is over.
        $this->pdo->prepare('UPDATE checkins SET created_at = ? WHERE id = ?')->execute(['2026-10-29 11:30:00', $this->c['old']->id]);
        $old = $this->repo->find($this->c['old']->id) ?? throw new \RuntimeException('missing');

        self::assertFalse($this->repo->isVisible($old, $this->vis()));
        self::assertSame(['yesterday', 'manual'], $this->names($this->repo->page(50, 0, $this->vis())));
        self::assertTrue($this->repo->isVisible($old, $this->vis(delay: 0)));
    }

    private function vis(?int $limit = null, int $delay = 2, bool $manual = true): Visibility
    {
        return new Visibility($limit, $delay, true, $manual, $this->now);
    }

    /** @param list<Checkin> $list @return list<string> */
    private function names(array $list): array
    {
        return array_map(fn (Checkin $c) => $c->placeName, $list);
    }

    public function testDelayHidesRecentCheckins(): void
    {
        self::assertSame(['yesterday', 'manual', 'old'], $this->names($this->repo->page(50, 0, $this->vis())));
        self::assertSame(3, $this->repo->count($this->vis()));
        self::assertFalse($this->repo->isVisible($this->c['recent'], $this->vis()));
        self::assertTrue($this->repo->isVisible($this->c['recent'], $this->vis(delay: 0)));
    }

    public function testManualPlacesCanBeHidden(): void
    {
        self::assertSame(['yesterday', 'old'], $this->names($this->repo->page(50, 0, $this->vis(manual: false))));
        self::assertFalse($this->repo->isVisible($this->c['manual'], $this->vis(manual: false)));
    }

    public function testLimitKeepsOnlyNewestAndBoundsPaging(): void
    {
        $vis = $this->vis(limit: 2);
        self::assertSame(2, $this->repo->count($vis));
        self::assertSame(['yesterday'], $this->names($this->repo->page(1, 0, $vis)));
        self::assertSame(['manual'], $this->names($this->repo->page(1, 1, $vis)));
        self::assertSame([], $this->repo->page(1, 2, $vis), 'No paging beyond the public window');
        self::assertTrue($this->repo->isVisible($this->c['manual'], $vis));
        self::assertFalse($this->repo->isVisible($this->c['old'], $vis), 'Third newest is outside the window');
        self::assertSame(['manual', 'yesterday'], array_column($this->repo->allForMap($vis), 'place_name'));
    }

    public function testNoVisibilityMeansEverything(): void
    {
        self::assertSame(4, $this->repo->count());
        self::assertCount(4, $this->repo->allForMap());
    }

    public function testAtPlaceRespectsVisibility(): void
    {
        self::assertSame(['recent', 'old'], $this->names($this->repo->atPlace('node', 1)));
        self::assertSame(['old'], $this->names($this->repo->atPlace('node', 1, $this->vis())));
    }
}
