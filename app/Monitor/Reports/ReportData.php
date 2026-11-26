<?php

namespace App\Monitor\Reports;

use App\Models\ClockEvent;
use App\Models\Gap;
use App\Models\Incident;
use App\Models\MinuteStat;
use App\Models\MonitorSession;
use App\Models\SpeedTest;
use App\Monitor\EvidenceChain;
use App\Monitor\NetworkState;

/**
 * The single canonical summary of a session. The dashboard, the HTML and PDF
 * reports and the complaint letters all read from this, so they cannot
 * disagree about a figure.
 */
final class ReportData
{
    public static function build(MonitorSession $session, bool $verifyChain = true, bool $withMinutes = true): array
    {
        $incidents = Incident::query()->where('monitor_session_id', $session->id)->orderBy('started_at')->get();
        $outages = $incidents->filter(fn ($i) => NetworkState::from($i->worst_state)->isOutage());
        $upstream = $outages->filter(fn ($i) => NetworkState::from($i->worst_state)->isUpstream());
        $gaps = Gap::query()->where('monitor_session_id', $session->id)->orderBy('started_at')->get();
        // The per-minute rows are only loaded for full reports; summaries use SQL aggregates,
        // which matters on a Raspberry Pi once a session holds weeks of minutes.
        $minuteQuery = fn () => MinuteStat::query()->where('monitor_session_id', $session->id);
        $minutes = $withMinutes ? $minuteQuery()->orderBy('minute_at')->get() : collect();
        $degradedStates = array_map(fn ($s) => $s->value, array_filter(NetworkState::cases(), fn ($s) => $s->severity() === 'degraded'));
        $agg = $minuteQuery()->whereNotNull('rtt_avg')
            ->selectRaw('avg(rtt_avg) as a, min(rtt_min) as lo, max(rtt_max) as hi, avg(jitter_avg) as j')->first();

        $monitored = (int) $session->monitored_ms;
        $outageMs = (int) $outages->sum('duration_reported_ms');
        $upstreamMs = (int) $upstream->sum('duration_reported_ms');
        $upstreamMinMs = (int) $upstream->sum('duration_min_ms');
        $upstreamMaxMs = (int) $upstream->sum('duration_max_ms');

        // Availability is measured against time actually watched. Pauses are
        // shown separately and never counted as the service working.
        $availability = $monitored > 0 ? max(0, 100 * (1 - $outageMs / $monitored)) : null;
        $upstreamAvailability = $monitored > 0 ? max(0, 100 * (1 - $upstreamMs / $monitored)) : null;

        $degradedMinutes = $minuteQuery()->whereIn('worst_state', $degradedStates)->count();

        $byDomain = [];
        foreach ($outages as $i) {
            $byDomain[$i->domain] ??= ['count' => 0, 'ms' => 0];
            $byDomain[$i->domain]['count']++;
            $byDomain[$i->domain]['ms'] += $i->duration_reported_ms;
        }

        return [
            'session' => $session,
            'generated_at' => now(),
            'monitored_ms' => $monitored,
            'gap_ms' => (int) $gaps->sum('duration_ms'),
            'samples' => (int) $session->samples,
            'incidents' => $incidents,
            'outages' => $outages->values(),
            'upstream' => $upstream->values(),
            'longest_upstream' => $upstream->sortByDesc('duration_reported_ms')->first(),
            'outage_ms' => $outageMs,
            'upstream_ms' => $upstreamMs,
            'upstream_min_ms' => $upstreamMinMs,
            'upstream_max_ms' => $upstreamMaxMs,
            'availability' => $availability,
            'upstream_availability' => $upstreamAvailability,
            'by_domain' => $byDomain,
            'degraded_minutes' => $degradedMinutes,
            'rtt_avg' => $agg?->a !== null ? round($agg->a, 1) : null,
            'rtt_min' => $agg?->lo !== null ? round($agg->lo, 1) : null,
            'rtt_max' => $agg?->hi !== null ? round($agg->hi, 1) : null,
            'jitter_avg' => $agg?->j,
            'gaps' => $gaps,
            'clock_events' => ClockEvent::query()->where('monitor_session_id', $session->id)->orderBy('observed_at')->get(),
            'speed_tests' => SpeedTest::query()->where('monitor_session_id', $session->id)->orderBy('measured_at')->get(),
            'minutes' => $minutes,
            'verdict' => self::verdict($upstream->count(), $outages->count(), $monitored),
            'quality' => self::quality($upstream, $monitored),
            'chain' => $verifyChain ? EvidenceChain::verify($session->chain_path) : null,
        ];
    }

    /** clean | upstream | local_only | insufficient */
    private static function verdict(int $upstream, int $outages, int $monitoredMs): string
    {
        return match (true) {
            $monitoredMs < 60_000 => 'insufficient',
            $upstream > 0 => 'upstream',
            $outages > 0 => 'local_only',
            default => 'clean',
        };
    }

    /**
     * How well the evidence supports what a complaint would say:
     * strong | moderate | limited | insufficient.
     */
    private static function quality($upstream, int $monitoredMs): string
    {
        if ($upstream->isEmpty() || $monitoredMs < 3_600_000) {
            return 'insufficient';
        }
        $bands = $upstream->pluck('confidence_band')->countBy();
        $strong = $bands->get('strong', 0);

        return match (true) {
            $monitoredMs >= 24 * 3_600_000 && $strong === $upstream->count() => 'strong',
            $strong + $bands->get('moderate', 0) >= $upstream->count() / 2 => 'moderate',
            default => 'limited',
        };
    }

    /**
     * Minute rows bucketed down to at most $maxPoints for charts.
     *
     * @return list<array{t:int, min:?float, avg:?float, max:?float, sev:string}>
     */
    public static function series($minutes, int $maxPoints = 240): array
    {
        $count = $minutes->count();
        if ($count === 0) {
            return [];
        }
        // Every minute of the period gets a slot, so a stretch with no rows (the
        // monitor was not running) is drawn as "not monitored" instead of vanishing.
        $byMinute = [];
        foreach ($minutes as $m) {
            $byMinute[$m->minute_at->getTimestamp()] = $m;
        }
        $first = array_key_first($byMinute);
        $last = array_key_last($byMinute);
        $slots = (int) (($last - $first) / 60) + 1;
        $per = max(1, (int) ceil($slots / $maxPoints));
        $rank = ['none' => -1, 'ok' => 0, 'info' => 1, 'degraded' => 2, 'outage' => 3];
        $out = [];

        for ($start = $first; $start <= $last; $start += $per * 60) {
            $sev = 'none';
            $min = $max = null;
            $sum = 0.0;
            $n = 0;
            for ($t = $start; $t < $start + $per * 60; $t += 60) {
                $m = $byMinute[$t] ?? null;
                if ($m === null) {
                    continue;
                }
                $s = $m->outage_samples > 0 ? 'outage' : NetworkState::from($m->worst_state)->severity();
                if ($rank[$s] > $rank[$sev]) {
                    $sev = $s;
                }
                if ($m->rtt_avg !== null) {
                    $min = $min === null ? (float) $m->rtt_min : min($min, (float) $m->rtt_min);
                    $max = $max === null ? (float) $m->rtt_max : max($max, (float) $m->rtt_max);
                    $sum += (float) $m->rtt_avg;
                    $n++;
                }
            }
            $out[] = ['t' => $start, 'min' => $min, 'avg' => $n ? round($sum / $n, 2) : null, 'max' => $max, 'sev' => $sev];
        }

        return $out;
    }
}
