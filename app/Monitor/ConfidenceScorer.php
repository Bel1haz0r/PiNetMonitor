<?php

namespace App\Monitor;

/**
 * Two-dimensional confidence for an incident's conclusion:
 *
 *   support  — of the signals that could be checked, the weighted share that
 *              agree with the conclusion
 *   coverage — of the signals relevant to the conclusion, the weighted share
 *              that could be checked at all
 *
 * Shown as a band, never as one falsely precise percentage.
 *
 * Evidence values are true / false / null (null = could not be checked).
 */
final class ConfidenceScorer
{
    /** Weights encode how much each signal narrows the cause. */
    private const WEIGHTS = [
        'link.stayedUp' => 10,
        'link.wired' => 6,
        'device.noGap' => 8,
        'device.noClockJump' => 5,
        'device.noSaturation' => 5,
        'wifi.signalHealthy' => 7,
        'wifi.noRoaming' => 5,
        'cpe.gatewayReachable' => 12,
        'upstream.icmpFailed' => 6,
        'upstream.tcpFailed' => 7,
        'upstream.tlsFailed' => 4,
        'upstream.publicDnsFailed' => 5,
        'upstream.httpFailed' => 4,
    ];

    /** @return array<string,bool> signal => value that supports the conclusion */
    private static function relevantTo(NetworkState $state): array
    {
        return match ($state) {
            NetworkState::CpeUpstreamUnreachable => array_fill_keys(array_keys(self::WEIGHTS), true),
            NetworkState::GatewayDown => [
                'link.stayedUp' => true, 'link.wired' => true, 'device.noGap' => true,
                'wifi.signalHealthy' => true, 'wifi.noRoaming' => true, 'cpe.gatewayReachable' => false,
            ],
            NetworkState::AdapterDown => [
                'link.stayedUp' => false, 'device.noGap' => true, 'device.noClockJump' => true,
            ],
            NetworkState::InternetDown => [
                'link.stayedUp' => true, 'device.noGap' => true, 'device.noClockJump' => true,
                'upstream.icmpFailed' => true, 'upstream.tcpFailed' => true, 'upstream.httpFailed' => true,
            ],
            default => array_fill_keys(array_keys(self::WEIGHTS), true),
        };
    }

    /**
     * @param  array<string,bool|null>  $evidence
     * @return array{support:int, coverage:int, band:string, items:array<string,string>}
     */
    public static function score(NetworkState $state, array $evidence): array
    {
        $relevant = self::relevantTo($state);
        $supporting = $checked = $relevantWeight = 0;
        $items = [];

        foreach (self::WEIGHTS as $key => $weight) {
            if (! array_key_exists($key, $relevant)) {
                $items[$key] = 'n/a';

                continue;
            }
            $relevantWeight += $weight;
            $value = $evidence[$key] ?? null;
            if ($value === null) {
                $items[$key] = 'unavailable';

                continue;
            }
            $checked += $weight;
            if ($value === $relevant[$key]) {
                $supporting += $weight;
                $items[$key] = 'supports';
            } else {
                $items[$key] = 'contradicts';
            }
        }

        // Nothing relevant could be checked: claiming any confidence would be an invention.
        if ($checked === 0 || $relevantWeight === 0) {
            return ['support' => 0, 'coverage' => 0, 'band' => 'insufficient', 'items' => $items];
        }

        $support = (int) round(100 * $supporting / $checked);
        $coverage = (int) round(100 * $checked / $relevantWeight);

        return ['support' => $support, 'coverage' => $coverage, 'band' => self::band($support, $coverage), 'items' => $items];
    }

    public static function band(int $support, int $coverage): string
    {
        return match (true) {
            $coverage < 40 => 'insufficient',
            $support >= 85 && $coverage >= 70 => 'strong',
            $support >= 70 => 'moderate',
            default => 'limited',
        };
    }
}
