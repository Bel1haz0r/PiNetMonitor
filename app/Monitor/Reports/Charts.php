<?php

namespace App\Monitor\Reports;

/**
 * Charts drawn directly as SVG, no library — the same visual language in the
 * report as in the dashboard. Latency is shown as the range from fastest to
 * slowest response with the average through it: the average alone would
 * smooth away exactly the spikes that ruin calls.
 */
final class Charts
{
    public const COLORS = ['ok' => '#2e9e6b', 'info' => '#8a94a6', 'degraded' => '#d99a1c', 'outage' => '#d64545', 'none' => '#e5e8ee'];

    /** @param list<array{t:int,min:?float,avg:?float,max:?float,sev:string}> $series */
    public static function latency(array $series, int $w = 720, int $h = 180): string
    {
        $pl = 44;
        $pr = 8;
        $pt = 8;
        $pb = 20;
        $iw = $w - $pl - $pr;
        $ih = $h - $pt - $pb;
        $max = 10.0;
        foreach ($series as $p) {
            $max = max($max, (float) ($p['max'] ?? 0));
        }
        $max = self::niceCeil($max);
        $n = max(1, count($series) - 1);
        $x = fn (int $i) => round($pl + $iw * $i / $n, 1);
        $y = fn (float $v) => round($pt + $ih - $ih * min($v, $max) / $max, 1);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        foreach ([0, 0.5, 1] as $f) {
            $gy = $y($max * $f);
            $svg .= '<line x1="'.$pl.'" y1="'.$gy.'" x2="'.($w - $pr).'" y2="'.$gy.'" stroke="#d9dde5" stroke-width="1"/>';
            $svg .= '<text x="'.($pl - 6).'" y="'.($gy + 4).'" font-size="10" text-anchor="end" fill="#5b6475" font-family="DejaVu Sans, sans-serif">'.round($max * $f).' ms</text>';
        }

        // Contiguous runs of measured points; a hole in the data is drawn as a hole.
        $run = [];
        $flush = function () use (&$run, &$svg, $x, $y) {
            if (count($run) > 1) {
                $top = $bottom = $avg = '';
                foreach ($run as [$i, $p]) {
                    $top .= $x($i).','.$y($p['max']).' ';
                    $avg .= $x($i).','.$y($p['avg']).' ';
                }
                foreach (array_reverse($run) as [$i, $p]) {
                    $bottom .= $x($i).','.$y($p['min']).' ';
                }
                $svg .= '<polygon points="'.trim($top.$bottom).'" fill="#3b82c4" fill-opacity="0.22"/>';
                $svg .= '<polyline points="'.trim($avg).'" fill="none" stroke="#1f5f99" stroke-width="1.5"/>';
            }
            $run = [];
        };
        foreach ($series as $i => $p) {
            if ($p['avg'] === null) {
                $flush();
            } else {
                $run[] = [$i, $p];
            }
        }
        $flush();

        return $svg.'</svg>';
    }

    /** One coloured slice per bucket: where the session was healthy, degraded or down. */
    public static function timeline(array $series, int $w = 720, int $h = 26): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $svg .= '<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="'.self::COLORS['none'].'"/>';
        $n = max(1, count($series));
        $sw = $w / $n;
        foreach ($series as $i => $p) {
            if ($p['sev'] !== 'none') {
                $svg .= '<rect x="'.round($i * $sw, 2).'" y="0" width="'.round($sw + 0.4, 2).'" height="'.$h.'" fill="'.self::COLORS[$p['sev']].'"/>';
            }
        }

        return $svg.'</svg>';
    }

    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function niceCeil(float $v): float
    {
        foreach ([10, 20, 50, 100, 200, 500, 1000, 2000, 5000] as $step) {
            if ($v <= $step) {
                return (float) $step;
            }
        }

        return ceil($v / 1000) * 1000;
    }
}
