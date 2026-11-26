<?php

namespace App\Monitor;

/**
 * Decides how fast to sample next. A healthy link is sampled slowly; once
 * something looks wrong the cadence escalates so outage boundaries are
 * resolved with sub-second precision.
 *
 * Driven only by observations and an externally supplied monotonic time, so
 * it is testable without a real clock. Intervals are tuned for a Raspberry Pi
 * (the desktop original goes down to 100 ms).
 */
final class AdaptiveCadence
{
    public string $phase = 'stable';

    private int $consecutiveUnclean = 0;

    private float $recoveryStartedMs = 0.0;

    /** @param array<string,int> $intervalsMs */
    public function __construct(
        private readonly array $intervalsMs = [
            'stable' => 1000, 'suspect' => 500, 'burst' => 250, 'incident' => 250, 'recovery' => 500,
        ],
        private readonly int $recoveryHoldMs = 30_000,
        private readonly int $burstThreshold = 2,
    ) {}

    /** @return int milliseconds until the next sample */
    public function observe(NetworkState $state, float $monoMs): int
    {
        $this->phase = $this->next($state, $monoMs);

        return $this->interval();
    }

    public function interval(): int
    {
        return $this->intervalsMs[$this->phase] ?? 1000;
    }

    private function next(NetworkState $state, float $monoMs): string
    {
        if ($state->isOutage()) {
            $this->consecutiveUnclean++;

            return 'incident';
        }

        // Info-level states (roaming, gap, own speed test) are not signs of a
        // failing link and must not drive escalation.
        if (! in_array($state->severity(), ['ok', 'info'], true)) {
            $this->consecutiveUnclean++;

            return $this->consecutiveUnclean >= $this->burstThreshold ? 'burst' : 'suspect';
        }

        $this->consecutiveUnclean = 0;

        if (in_array($this->phase, ['incident', 'burst', 'suspect'], true)) {
            $this->recoveryStartedMs = $monoMs;

            return 'recovery';
        }

        if ($this->phase === 'recovery') {
            return $monoMs - $this->recoveryStartedMs >= $this->recoveryHoldMs ? 'stable' : 'recovery';
        }

        return 'stable';
    }
}
