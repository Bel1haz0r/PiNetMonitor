<?php

namespace App\Monitor;

use Illuminate\Support\Str;

/**
 * Groups consecutive failing samples into outage segments and records the
 * boundary samples on both sides, so durations are reported with honest
 * bounds rather than as one invented number:
 *
 *   min      = lastBad - firstBad            (cannot be disputed)
 *   max      = firstGood - lastGood          (upper bound)
 *   reported = midpoint-to-midpoint estimate (always between the two)
 *
 * A pause in monitoring closes the current segment: nothing was observed
 * during the pause, so nothing about it may be claimed. If failures continue
 * afterwards the new segment carries the same correlation id.
 *
 * Instants are arrays: ['mono' => float ms, 'wall' => float unix seconds].
 */
final class IncidentDetector
{
    /** @var list<NetworkState> */
    private array $statesSeen = [];

    private ?array $lastGood = null;

    private ?array $firstBad = null;

    private ?array $lastBad = null;

    private string $firstDetail = '';

    private int $sampleCount = 0;

    private string $correlationId;

    private bool $startedAfterGap = false;

    private ?string $carriedCorrelation = null;

    public function __construct(private int $closedCount = 0)
    {
        $this->correlationId = (string) Str::uuid();
    }

    public function hasOpenIncident(): bool
    {
        return $this->firstBad !== null;
    }

    /** Snapshot of the segment in progress, or null. */
    public function open(): ?array
    {
        return $this->firstBad === null ? null : $this->build(null, null, open: true);
    }

    /** Feeds one classified sample in. Returns a record only on the sample that closes a segment. */
    public function observe(array $instant, NetworkState $state, string $detail = ''): ?array
    {
        if ($state === NetworkState::MonitoringGap) {
            return null;
        }

        if ($state->isOutage()) {
            if ($this->firstBad === null) {
                $this->firstBad = $instant;
                $this->firstDetail = $detail;
                $this->sampleCount = 0;
                $this->statesSeen = [];
                $this->startedAfterGap = $this->carriedCorrelation !== null;
                $this->correlationId = $this->carriedCorrelation ?? (string) Str::uuid();
                $this->carriedCorrelation = null;
            }
            $this->lastBad = $instant;
            $this->sampleCount++;
            if (! in_array($state, $this->statesSeen, true)) {
                $this->statesSeen[] = $state;
            }

            return null;
        }

        // Healthy sample: any event that survived a pause has now ended.
        $this->carriedCorrelation = null;
        $closed = $this->firstBad !== null ? $this->close($instant, null) : null;
        $this->lastGood = $instant;

        return $closed;
    }

    /**
     * Monitoring stopped for a while. Closes any segment in progress at the
     * moment observation ended ($gapStartedMono = last sample before the pause).
     */
    public function observeGap(float $gapStartedMono): ?array
    {
        $cut = null;
        if ($this->firstBad !== null) {
            $this->carriedCorrelation = $this->correlationId;
            $cut = $this->close(null, $gapStartedMono);
        }
        // The last healthy sample is on the far side of the pause and says
        // nothing about when a later outage began.
        $this->lastGood = null;

        return $cut;
    }

    /** Closes a segment still running when monitoring stops. Marked open: service was never seen to return. */
    public function closeOpenIncident(): ?array
    {
        return $this->firstBad === null ? null : $this->close(null, null);
    }

    private function close(?array $firstGood, ?float $gapStartedMono): array
    {
        $this->closedCount++;
        $record = $this->build($firstGood, $gapStartedMono, open: false);
        $this->firstBad = null;
        $this->lastBad = null;
        $this->sampleCount = 0;
        $this->startedAfterGap = false;
        $this->statesSeen = [];

        return $record;
    }

    private function build(?array $firstGood, ?float $gapStartedMono, bool $open): array
    {
        $firstBadMono = $this->firstBad['mono'];
        $lastBadMono = $this->lastBad['mono'];
        $startFloor = $this->lastGood['mono'] ?? $firstBadMono;
        $measuredEnd = $firstGood['mono'] ?? $gapStartedMono ?? $lastBadMono;

        $worst = $this->statesSeen[0];
        foreach ($this->statesSeen as $s) {
            if ($s->rank() > $worst->rank()) {
                $worst = $s;
            }
        }

        $min = $lastBadMono - $firstBadMono;
        $max = $measuredEnd - $startFloor;
        $reported = (($lastBadMono + $measuredEnd) / 2) - (($startFloor + $firstBadMono) / 2);

        return [
            'number' => $open ? $this->closedCount + 1 : $this->closedCount,
            'correlation_id' => $this->correlationId,
            'worst_state' => $worst,
            'states_seen' => array_map(fn (NetworkState $s) => $s->value, $this->statesSeen),
            'started_wall' => $this->firstBad['wall'],
            'ended_wall' => ($firstGood ?? $this->lastBad)['wall'],
            'duration_min_ms' => (int) round($min),
            'duration_max_ms' => (int) round($max),
            'duration_reported_ms' => (int) round($reported),
            'sample_count' => $this->sampleCount,
            'detail' => $this->firstDetail,
            'ended_by_gap' => $gapStartedMono !== null,
            'started_after_gap' => $this->startedAfterGap,
            'is_open' => $open || ($firstGood === null && $gapStartedMono === null),
        ];
    }
}
