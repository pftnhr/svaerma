<?php

declare(strict_types=1);

namespace App\Tests;

use App\Format;

final class FormatTest extends TestCase
{
    public function testGermanDay(): void
    {
        self::assertSame('Donnerstag, 29. Oktober 2026', Format::day(new \DateTimeImmutable('2026-10-29')));
        self::assertSame('Sonntag, 1. März 2026', Format::day(new \DateTimeImmutable('2026-03-01')));

        $now = new \DateTimeImmutable('2026-11-14');
        self::assertSame('Donnerstag, 29. Oktober', Format::dayInYear(new \DateTimeImmutable('2026-10-29'), $now));
        self::assertSame('Mittwoch, 29. Oktober 2025', Format::dayInYear(new \DateTimeImmutable('2025-10-29'), $now));
        self::assertSame('Do, 29. Oktober', Format::shortDay(new \DateTimeImmutable('2026-10-29'), $now));
        self::assertSame('Mi, 29. Oktober 2025', Format::shortDay(new \DateTimeImmutable('2025-10-29'), $now));
    }

    public function testNumber(): void
    {
        self::assertSame('12.345', Format::number(12345));
    }
}
