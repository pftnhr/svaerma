<?php

declare(strict_types=1);

namespace App;

/**
 * German date formatting without depending on the intl extension.
 */
final class Format
{
    private const DAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** "Mittwoch, 29. Oktober 2026" */
    public static function day(\DateTimeInterface $date): string
    {
        return sprintf(
            '%s, %d. %s %s',
            self::DAYS[(int) $date->format('w')],
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n') - 1],
            $date->format('Y'),
        );
    }

    /** "Donnerstag, 29. Oktober", with the year only when it is not the current one */
    public static function dayInYear(\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        $day = self::day($date);
        return self::isCurrentYear($date, $now) ? substr($day, 0, -5) : $day;
    }

    /** "Do, 29. Oktober", with the year only when it is not the current one */
    public static function shortDay(\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        return sprintf(
            '%s, %d. %s%s',
            mb_substr(self::DAYS[(int) $date->format('w')], 0, 2),
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n') - 1],
            self::isCurrentYear($date, $now) ? '' : ' ' . $date->format('Y'),
        );
    }

    private static function isCurrentYear(\DateTimeInterface $date, ?\DateTimeInterface $now): bool
    {
        $now ??= new \DateTimeImmutable('now', $date->getTimezone());
        return $date->format('Y') === $now->format('Y');
    }

    /** "29.10.2026, 14:05" */
    public static function dateTime(\DateTimeInterface $date): string
    {
        return $date->format('d.m.Y, H:i');
    }

    /** "1.234" */
    public static function number(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
