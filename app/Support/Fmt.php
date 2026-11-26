<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Locale-aware formatting shared by reports, letters and exports. */
final class Fmt
{
    public static function duration(int|float $ms, bool $subSecond = true): string
    {
        $ms = max(0, (float) $ms);
        if ($ms < 60_000) {
            return $subSecond && $ms < 10_000
                ? number_format($ms / 1000, 1, app()->getLocale() === 'sr' ? ',' : '.', '').' s'
                : round($ms / 1000).' s';
        }
        $s = (int) round($ms / 1000);
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'min' => 60, 's' => 1] as $unit => $size) {
            if ($s >= $size) {
                $parts[] = intdiv($s, $size).' '.$unit;
                $s %= $size;
            }
        }

        return implode(' ', array_slice($parts, 0, 3));
    }

    public static function percent(?float $value, string $locale): string
    {
        if ($value === null) {
            return '—';
        }
        // Never round up to a clean 100 when something was lost.
        $shown = $value < 100 && $value > 99.999 ? 99.999 : $value;

        return number_format($shown, 3, $locale === 'sr' ? ',' : '.', '').' %';
    }

    public static function number(?float $value, string $locale, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format($value, $decimals, $locale === 'sr' ? ',' : '.', '');
    }

    public static function date(?CarbonInterface $date, string $locale): string
    {
        return $date === null ? '—' : $date->format($locale === 'sr' ? 'd.m.Y.' : 'Y-m-d');
    }

    public static function dateTime(?CarbonInterface $date, string $locale): string
    {
        if ($date === null) {
            return '—';
        }

        return $date->copy()->setTimezone(config('monitor.display_timezone'))
            ->format($locale === 'sr' ? 'd.m.Y. H:i:s' : 'Y-m-d H:i:s');
    }

    /** Serbian has three plural forms; English uses the first two arguments. */
    public static function plural(int $n, string $one, string $few, string $many, string $locale): string
    {
        if ($locale !== 'sr') {
            return $n === 1 ? $one : $few;
        }
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? $few : $many;
    }

    public static function wrap(string $text, int $width = 78): string
    {
        return wordwrap(preg_replace('/\s+/', ' ', trim($text)), $width, "\n", false);
    }
}
