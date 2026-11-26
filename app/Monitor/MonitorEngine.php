<?php

namespace App\Monitor;

use App\Models\ClockEvent;
use App\Models\Gap;
use App\Models\Incident;
use App\Models\MinuteStat;
use App\Models\MonitorCommand;
use App\Models\MonitorSession;
use App\Models\Setting;
use App\Models\SpeedTest as SpeedTestRow;
use App\Monitor\Probes\DeepProbes;
use App\Monitor\Probes\FastProbes;
use App\Monitor\Probes\LinkInspector;
use App\Monitor\Speed\SpeedTest;
use App\Monitor\Speed\SpeedtestCli;
use Illuminate\Support\Carbon;

/**
 * The monitoring daemon: sample, classify, detect incidents, write evidence.
 *
 * Every finding is written to the hash chain first and to SQLite second; the
 * chain is forced to disk on every state change. A monitor whose purpose is to
 * survive a power cut has no business holding findings in memory.
 *
 * Raspberry Pi adaptation of the original's storage model: healthy samples are
 * aggregated per minute instead of being stored one by one (SD-card wear),
 * while every sample around and during a fault is recorded individually.
 */
class MonitorEngine
{
    public const VERSION = '1.0.0';

    public bool $stop = false;

    /** Test hook: fn(float $elapsedSec): ?string returning 'upstream' | 'gateway' | null. */
    public $faultInjector = null;

    /** @var callable|null */
    private $log = null;

    private array $settings = [];

    private LinkInspector $inspector;

    private FastProbes $fast;

    private DeepProbes $deep;

    private StateClassifier $classifier;

    private AdaptiveCadence $cadence;

    private IncidentDetector $detector;

    private EvidenceChain $chain;

    private MonitorSession $session;

    private float $startedMono;

    private ?float $lastMono = null;

    private ?float $lastWall = null;

    private ?NetworkState $lastState = null;

    private float $stateSinceWall = 0.0;

    private ?array $pendingSample = null;     // last sample not yet written to the chain

    private float $lastChainSampleMono = 0.0;

    private ?float $failingSince = null;

    private array $rttWindow = [];

    private array $recent = [];

    private ?array $minute = null;

    private ?array $acc = null;               // evidence accumulator of the open incident

    private float $lastOpenFlushMono = 0.0;

    private ?int $lastTrafficBytes = null;

    private float $lastCommandCheck = 0.0;

    private string $lastScheduleMinute = '';

    private ?LinkSnapshot $link = null;

    private bool $speedRunning = false;

    private string $detail = '';

    private ?array $lastSample = null;

    public function __construct(?LinkInspector $inspector = null, ?FastProbes $fast = null, ?DeepProbes $deep = null)
    {
        $this->settings = Setting::allMerged();
        $this->inspector = $inspector ?? new LinkInspector($this->settings['interface'] ?: null);
        $this->fast = $fast ?? new FastProbes;
        $this->deep = $deep ?? new DeepProbes;
    }

    public function run(?callable $log = null, ?float $maxSeconds = null): void
    {
        $this->log = $log;
        $this->startedMono = $this->mono();
        $this->configure();
        $this->resumeOrStart();

        // Warm-up cycle: the first socket round trip carries setup cost and is discarded.
        $this->link = $this->inspector->snapshot(true);
        $this->fast->run(array_filter([$this->link->gateway]), [], 300);

        while (! $this->stop) {
            $tickStart = $this->mono();
            $interval = $this->tick();
            if ($maxSeconds !== null && ($this->mono() - $this->startedMono) / 1000 >= $maxSeconds) {
                break;
            }
            $this->sleepUntil($tickStart + $interval);
        }

        $this->shutdown();
    }

    // ------------------------------------------------------------------ setup

    private function configure(): void
    {
        $s = $this->settings = Setting::allMerged();
        $this->classifier = new StateClassifier((float) $s['high_latency_ms'], (float) $s['high_jitter_ms']);
        $this->cadence = new AdaptiveCadence([
            'stable' => (int) $s['interval_stable_ms'], 'suspect' => (int) $s['interval_suspect_ms'],
            'burst' => (int) $s['interval_burst_ms'], 'incident' => (int) $s['interval_incident_ms'],
            'recovery' => (int) $s['interval_recovery_ms'],
        ]);
    }

    private function resumeOrStart(): void
    {
        $open = MonitorSession::query()->where('status', 'open')->latest('id')->first();
        if ($open === null) {
            $this->startSession();

            return;
        }

        $this->session = $open;
        $this->chain = new EvidenceChain($open->chain_path);
        $state = $this->chain->open();
        $now = microtime(true);
        $last = ($open->last_sample_at ?? $open->started_at)->getPreciseTimestamp(3) / 1000;

        // Whatever was in progress when the process died was never seen to end.
        Incident::query()->where('monitor_session_id', $open->id)->where('is_open', true)
            ->update(['ended_by_gap' => true]);

        $this->detector = new IncidentDetector(Incident::query()->where('monitor_session_id', $open->id)->count());
        $open->increment('runs');

        $this->chain->append('Resume', [
            'run' => $open->runs, 'version' => self::VERSION, 'torn_tail_removed' => $state['truncated'],
        ], true);

        // Time the monitor was not running is a pause, never an outage: nothing was measured.
        if ($now - $last > 1.0) {
            $this->recordGap($last, $now, 'MonitorNotRunning');
        }
        $this->info("Resumed {$open->name} (run {$open->runs}).");
    }

    private function startSession(): void
    {
        $name = 'Session_'.Carbon::now(config('monitor.display_timezone'))->format('Ymd_His');
        $dir = rtrim(config('monitor.storage_dir'), '/').'/sessions/'.$name;
        $link = $this->inspector->snapshot(true);

        $this->chain = new EvidenceChain($dir.'/RawEvidence.jsonl');
        $this->chain->open();
        $this->session = MonitorSession::query()->create([
            'name' => $name, 'status' => 'open', 'started_at' => Carbon::now(), 'runs' => 1,
            'interface' => $link->interface, 'gateway' => $link->gateway, 'wireless' => $link->wireless,
            'chain_path' => $this->chain->path(),
        ]);
        $this->detector = new IncidentDetector;
        $this->chain->append('SessionStart', [
            'session' => $name, 'version' => self::VERSION, 'host' => php_uname('n'),
            'os' => PHP_OS_FAMILY.' '.php_uname('m'), 'link' => $link->toArray(),
            'icmp_targets' => $this->settings['icmp_targets'], 'tcp_targets' => $this->settings['tcp_targets'],
            'icmp_method' => $this->fast->usesRawIcmp() ? 'socket' : 'ping-binary',
            'timezone' => config('monitor.display_timezone'),
        ], true);
        $this->checkpoint();
        $this->info("Started $name.");
    }

    // ------------------------------------------------------------------- tick

    /** Takes one sample. Returns the interval in ms until the next one. */
    private function tick(): int
    {
        $mono = $this->mono();
        $wall = microtime(true);
        $s = $this->settings;

        if ($this->lastMono !== null) {
            $delta = $mono - $this->lastMono;
            $wallDelta = ($wall - $this->lastWall) * 1000;
            if ($delta > $s['gap_threshold_s'] * 1000) {
                // Sampling stopped: the process was starved or the device suspended.
                $this->recordGap($this->lastWall, $wall, 'Suspended', $this->lastMono);
            } else {
                $this->session->monitored_ms += (int) round($delta);
                // The wall clock moved differently from the monotonic one (NTP step, manual
                // change). Noted — durations never come from the wall clock.
                if (abs($wallDelta - $delta) > 2000) {
                    ClockEvent::query()->create([
                        'monitor_session_id' => $this->session->id, 'observed_at' => $this->carbon($wall),
                        'jump_seconds' => round(($wallDelta - $delta) / 1000, 3),
                    ]);
                    $this->chain->append('ClockJump', ['seconds' => round(($wallDelta - $delta) / 1000, 3)], true, $wall);
                    if ($this->acc !== null) {
                        $this->acc['clockJump'] = true;
                    }
                }
            }
        }

        // ---- probes ---------------------------------------------------------
        $link = $this->link = $this->inspector->snapshot();
        $timeout = (int) ($this->cadence->phase === 'stable' ? $s['probe_timeout_ms'] : $s['probe_timeout_fast_ms']);
        $icmpTargets = $link->isUp ? array_values(array_filter([$link->gateway, ...$s['icmp_targets']])) : [];
        $res = $this->fast->run($icmpTargets, $link->isUp ? $s['tcp_targets'] : [], $timeout);

        $fault = $this->faultInjector ? ($this->faultInjector)(($mono - $this->startedMono) / 1000) : null;
        if ($fault !== null) {
            foreach ($res['icmp'] as $t => $v) {
                $res['icmp'][$t] = ($fault === 'upstream' && $t === $link->gateway) ? $v : null;
            }
            $res['tcp'] = array_fill_keys(array_keys($res['tcp']), null);
        }

        $gwRtt = $link->gateway !== null ? ($res['icmp'][$link->gateway] ?? null) : null;
        $gateway = ($link->gateway !== null && $link->isUp) ? new ProbeGroup([$link->gateway => $gwRtt]) : ProbeGroup::none();
        $extIcmp = new ProbeGroup(array_diff_key($res['icmp'], [(string) $link->gateway => 1]), $link->isUp);
        $extTcp = new ProbeGroup($res['tcp'], $link->isUp);

        $fastOk = $extIcmp->anySuccess() || $extTcp->anySuccess();
        if ($fastOk) {
            $this->failingSince = null;
        } else {
            $this->failingSince ??= $mono;
        }

        // ---- slow probes, in the background ---------------------------------
        $this->deep->poll();
        $deepEvery = ($this->cadence->phase === 'stable' ? (int) $s['deep_interval_s'] : 5) * 1000;
        $needsFresh = $this->failingSince !== null && $this->deep->startedMonoMs < $this->failingSince;
        if (! $this->deep->isRunning() && $link->isUp && $fault === null
            && ($this->deep->startedMonoMs === 0.0 || $mono - $this->deep->startedMonoMs >= $deepEvery || $needsFresh)) {
            $this->deep->start($link->resolvers, $s['dns_public']);
        }
        $deep = $this->deep->groups($mono, $s['deep_interval_s'] * 2500, $this->failingSince ?? 0.0);
        if ($fault !== null) {
            $deep = ['http' => ProbeGroup::none(), 'tls' => ProbeGroup::none(), 'dnsAssigned' => ProbeGroup::none(),
                'dnsPublic' => ProbeGroup::none(), 'captive' => false, 'fresh' => false];
        }

        // ---- classify -------------------------------------------------------
        $cycle = new ProbeCycle($link, $gateway, $extIcmp, $extTcp, $deep['http'], $deep['tls'],
            $deep['dnsAssigned'], $deep['dnsPublic'], $deep['captive']);

        $rtt = $cycle->averageExternalRtt();
        if ($rtt !== null) {
            $this->rttWindow[] = $rtt;
            $this->rttWindow = array_slice($this->rttWindow, -10);
        }
        [$state, $detail] = $this->classifier->classify($cycle, [
            'jitter' => $this->jitter(), 'bssidChanged' => $this->inspector->consumeBssidChange(),
        ]);
        $this->detail = $detail;

        // ---- record ---------------------------------------------------------
        $sample = [
            's' => $state->value, 'm' => round($mono, 1), 'run' => $this->session->runs,
            'up' => $link->isUp, 'gw' => $gwRtt, 'icmp' => $extIcmp->results, 'tcp' => $extTcp->results,
        ];
        $this->lastSample = ['gw' => $gwRtt, 'icmp' => $extIcmp->results, 'tcp' => $extTcp->results];
        $changed = $state !== $this->lastState;
        if ($changed) {
            // The sample just before a change is one of the two boundaries the duration
            // bounds rest on, so it goes into the chain even if it was a healthy one.
            if ($this->pendingSample !== null) {
                $this->chain->append('Sample', $this->pendingSample['p'], false, $this->pendingSample['t']);
            }
            $this->chain->append('Sample', $sample + ['d' => $detail], true, $wall);
            $this->pendingSample = null;
            $this->lastChainSampleMono = $mono;
            $this->stateSinceWall = $wall;
            $this->info(sprintf('%s → %s  %s', $this->lastState?->value ?? 'start', $state->value, $detail));
        } elseif ($state !== NetworkState::Ok && $mono - $this->lastChainSampleMono >= 5000) {
            $this->chain->append('Sample', $sample, false, $wall);
            $this->pendingSample = null;
            $this->lastChainSampleMono = $mono;
        } else {
            $this->pendingSample = ['p' => $sample, 't' => $wall];
        }

        $this->session->samples++;
        $this->aggregate($wall, $state, $extIcmp, $gwRtt);
        $this->recent[] = [(int) round($wall * 1000), $rtt === null ? null : round($rtt, 1),
            $gwRtt, $state->value, $this->minOf($extIcmp), $this->maxOf($extIcmp)];
        $this->recent = array_slice($this->recent, -300);

        // ---- incidents ------------------------------------------------------
        $wasOpen = $this->detector->hasOpenIncident();
        $closed = $this->detector->observe(['mono' => $mono, 'wall' => $wall], $state, $detail);
        if ($closed !== null) {
            $this->finalizeIncident($closed);
        }
        if ($this->detector->hasOpenIncident()) {
            if (! $wasOpen) {
                $this->acc = ['linkUp' => true, 'gwSeen' => false, 'gwOk' => true, 'icmpTried' => false,
                    'icmpAllFailed' => true, 'tcpTried' => false, 'tcpAllFailed' => true, 'deep' => null,
                    'clockJump' => false, 'peakBps' => null, 'minSignal' => null, 'roamed' => false,
                    'wireless' => $link->wireless];
                $this->lastOpenFlushMono = 0.0;
            }
            $this->accumulate($cycle, $deep, $mono);
            if ($mono - $this->lastOpenFlushMono >= 5000) {
                $this->persistIncident($this->detector->open());
                $this->lastOpenFlushMono = $mono;
            }
        }

        $this->lastState = $state;
        $this->lastMono = $mono;
        $this->lastWall = $wall;
        $interval = $this->cadence->observe($state, $mono);

        $this->writeStatus($state, $deep);

        if ($mono - $this->lastCommandCheck >= 2000) {
            $this->lastCommandCheck = $mono;
            $this->handleCommands();
            $this->handleSchedule();
        }

        return $interval;
    }

    private function jitter(): ?float
    {
        $n = count($this->rttWindow);
        if ($n < 5) {
            return null;
        }
        $sum = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $sum += abs($this->rttWindow[$i] - $this->rttWindow[$i - 1]);
        }

        return $sum / ($n - 1);
    }

    private function minOf(ProbeGroup $g): ?float
    {
        $ok = array_filter($g->results, fn ($v) => $v !== null);

        return $ok ? min($ok) : null;
    }

    private function maxOf(ProbeGroup $g): ?float
    {
        $ok = array_filter($g->results, fn ($v) => $v !== null);

        return $ok ? max($ok) : null;
    }

    // -------------------------------------------------------- minute rollups

    private function aggregate(float $wall, NetworkState $state, ProbeGroup $extIcmp, ?float $gwRtt): void
    {
        $key = (int) floor($wall / 60) * 60;
        if ($this->minute !== null && $this->minute['key'] !== $key) {
            $this->flushMinute();
        }
        $this->minute ??= ['key' => $key, 'n' => 0, 'ok' => 0, 'out' => 0, 'min' => null, 'max' => null,
            'sum' => 0.0, 'cnt' => 0, 'gwSum' => 0.0, 'gwCnt' => 0, 'jSum' => 0.0, 'jCnt' => 0, 'worst' => NetworkState::Ok];

        $m = &$this->minute;
        $m['n']++;
        $m['ok'] += $state === NetworkState::Ok ? 1 : 0;
        $m['out'] += $state->isOutage() ? 1 : 0;
        if ($state->rank() > $m['worst']->rank()) {
            $m['worst'] = $state;
        }
        foreach ($extIcmp->results as $r) {
            if ($r !== null) {
                $m['min'] = $m['min'] === null ? $r : min($m['min'], $r);
                $m['max'] = $m['max'] === null ? $r : max($m['max'], $r);
                $m['sum'] += $r;
                $m['cnt']++;
            }
        }
        if ($gwRtt !== null) {
            $m['gwSum'] += $gwRtt;
            $m['gwCnt']++;
        }
        if (($j = $this->jitter()) !== null) {
            $m['jSum'] += $j;
            $m['jCnt']++;
        }
    }

    private function flushMinute(): void
    {
        if ($this->minute === null) {
            return;
        }
        $m = $this->minute;
        $this->minute = null;
        $row = [
            'samples' => $m['n'], 'ok_samples' => $m['ok'], 'outage_samples' => $m['out'],
            'rtt_min' => $m['min'], 'rtt_avg' => $m['cnt'] ? round($m['sum'] / $m['cnt'], 2) : null, 'rtt_max' => $m['max'],
            'gateway_rtt_avg' => $m['gwCnt'] ? round($m['gwSum'] / $m['gwCnt'], 2) : null,
            'jitter_avg' => $m['jCnt'] ? round($m['jSum'] / $m['jCnt'], 2) : null,
            'worst_state' => $m['worst']->value,
        ];
        $this->chain->append('Minute', ['at' => gmdate('Y-m-d\TH:i:00\Z', $m['key'])] + $row, false, $m['key'] + 60);
        MinuteStat::query()->updateOrCreate(
            ['monitor_session_id' => $this->session->id, 'minute_at' => Carbon::createFromTimestamp($m['key'])], $row
        );
        $this->checkpoint();
    }

    /** Persists running totals so a resume continues from exact, already-written figures. */
    private function checkpoint(): void
    {
        $this->session->fill([
            'last_sample_at' => $this->lastWall ? $this->carbon($this->lastWall) : $this->session->last_sample_at,
            'last_hash' => $this->chain->lastHash(), 'chain_entries' => $this->chain->count(),
            'interface' => $this->link?->interface ?? $this->session->interface,
            'gateway' => $this->link?->gateway ?? $this->session->gateway,
            'wireless' => $this->link?->wireless ?? $this->session->wireless,
        ])->save();
    }

    // -------------------------------------------------------------- incidents

    private function accumulate(ProbeCycle $c, array $deep, float $mono): void
    {
        $a = &$this->acc;
        $a['linkUp'] = $a['linkUp'] && $c->link->isUp;
        if ($c->gateway->attempted() > 0) {
            $a['gwSeen'] = true;
            $a['gwOk'] = $a['gwOk'] && $c->gateway->allSucceeded();
        }
        if ($c->externalIcmp->attempted() > 0) {
            $a['icmpTried'] = true;
            $a['icmpAllFailed'] = $a['icmpAllFailed'] && $c->externalIcmp->allFailed();
        }
        if ($c->externalTcp->attempted() > 0) {
            $a['tcpTried'] = true;
            $a['tcpAllFailed'] = $a['tcpAllFailed'] && $c->externalTcp->allFailed();
        }
        if ($deep['fresh']) {
            $a['deep'] = [
                'tls' => $deep['tls']->attempted() ? $deep['tls']->allFailed() : null,
                'http' => $deep['http']->attempted() ? $deep['http']->allFailed() : null,
                'dns' => $deep['dnsPublic']->attempted() ? $deep['dnsPublic']->allFailed() : null,
            ];
        }
        if ($c->link->signalDbm !== null) {
            $a['minSignal'] = min($a['minSignal'] ?? 0, $c->link->signalDbm);
        }

        // How much this device itself was using the link. An outage that coincides
        // with our own heavy traffic is not clean evidence about the line.
        $bytes = $this->inspector->trafficBytes();
        if ($bytes !== null && $this->lastTrafficBytes !== null && $this->lastMono !== null && $mono > $this->lastMono) {
            $bps = (int) (($bytes - $this->lastTrafficBytes) / (($mono - $this->lastMono) / 1000));
            $a['peakBps'] = max($a['peakBps'] ?? 0, $bps);
        }
        $this->lastTrafficBytes = $bytes;
    }

    private function finalizeIncident(array $record): void
    {
        $a = $this->acc ?? [];
        $wireless = $a['wireless'] ?? false;
        $evidence = [
            'link.stayedUp' => $a['linkUp'] ?? null,
            'link.wired' => ! $wireless,
            'device.noGap' => ! ($record['ended_by_gap'] || $record['started_after_gap']),
            'device.noClockJump' => ! ($a['clockJump'] ?? false),
            'device.noSaturation' => isset($a['peakBps']) ? $a['peakBps'] < 1_000_000 : null,
            'wifi.signalHealthy' => $wireless ? (isset($a['minSignal']) ? $a['minSignal'] > -75 : null) : true,
            'wifi.noRoaming' => $wireless ? ! ($a['roamed'] ?? false) : true,
            'cpe.gatewayReachable' => ($a['gwSeen'] ?? false) ? $a['gwOk'] : null,
            'upstream.icmpFailed' => ($a['icmpTried'] ?? false) ? $a['icmpAllFailed'] : null,
            'upstream.tcpFailed' => ($a['tcpTried'] ?? false) ? $a['tcpAllFailed'] : null,
            'upstream.tlsFailed' => $a['deep']['tls'] ?? null,
            'upstream.httpFailed' => $a['deep']['http'] ?? null,
            'upstream.publicDnsFailed' => $a['deep']['dns'] ?? null,
        ];
        $score = ConfidenceScorer::score($record['worst_state'], $evidence);
        $this->persistIncident($record, $score, $a['peakBps'] ?? null);
        $this->acc = null;

        $this->chain->append('Incident', [
            'number' => $record['number'], 'correlation' => $record['correlation_id'],
            'worst' => $record['worst_state']->value, 'states' => $record['states_seen'],
            'started' => EvidenceChain::iso($record['started_wall']), 'ended' => EvidenceChain::iso($record['ended_wall']),
            'min_ms' => $record['duration_min_ms'], 'max_ms' => $record['duration_max_ms'],
            'reported_ms' => $record['duration_reported_ms'], 'samples' => $record['sample_count'],
            'ended_by_gap' => $record['ended_by_gap'], 'started_after_gap' => $record['started_after_gap'],
            'open' => $record['is_open'], 'support' => $score['support'], 'coverage' => $score['coverage'],
            'evidence' => $score['items'],
        ], true);
        $this->checkpoint();
        $this->info(sprintf('Incident #%d closed: %s, %.1f s (min %.1f, max %.1f).', $record['number'],
            $record['worst_state']->value, $record['duration_reported_ms'] / 1000,
            $record['duration_min_ms'] / 1000, $record['duration_max_ms'] / 1000));
    }

    private function persistIncident(array $r, ?array $score = null, ?int $peakBps = null): void
    {
        Incident::query()->updateOrCreate(
            ['monitor_session_id' => $this->session->id, 'number' => $r['number']],
            [
                'correlation_id' => $r['correlation_id'], 'worst_state' => $r['worst_state']->value,
                'states_seen' => $r['states_seen'], 'domain' => $r['worst_state']->domain($this->link?->wireless ?? false),
                'started_at' => $this->carbon($r['started_wall']), 'ended_at' => $this->carbon($r['ended_wall']),
                'duration_min_ms' => max(0, $r['duration_min_ms']), 'duration_max_ms' => max(0, $r['duration_max_ms']),
                'duration_reported_ms' => max(0, $r['duration_reported_ms']), 'sample_count' => $r['sample_count'],
                'detail' => $r['detail'], 'ended_by_gap' => $r['ended_by_gap'],
                'started_after_gap' => $r['started_after_gap'], 'is_open' => $r['is_open'],
                'confidence_support' => $score['support'] ?? null, 'confidence_coverage' => $score['coverage'] ?? null,
                'confidence_band' => $score['band'] ?? null, 'evidence' => $score['items'] ?? null,
                'peak_local_bps' => $peakBps,
            ]
        );
    }

    /** A stretch during which nothing was observed. Cuts any incident in progress. */
    private function recordGap(float $fromWall, float $toWall, string $cause, ?float $fromMono = null): void
    {
        if ($fromMono !== null && ($cut = $this->detector->observeGap($fromMono)) !== null) {
            $this->finalizeIncident($cut);
        }
        Gap::query()->create([
            'monitor_session_id' => $this->session->id, 'started_at' => $this->carbon($fromWall),
            'ended_at' => $this->carbon($toWall), 'duration_ms' => (int) round(($toWall - $fromWall) * 1000), 'cause' => $cause,
        ]);
        $this->chain->append('Gap', [
            'from' => EvidenceChain::iso($fromWall), 'to' => EvidenceChain::iso($toWall),
            'seconds' => round($toWall - $fromWall, 1), 'cause' => $cause,
        ], true, $toWall);
        $this->rttWindow = [];
        $this->lastState = null;
        $this->pendingSample = null;
        $this->info(sprintf('Monitoring gap of %.0f s (%s).', $toWall - $fromWall, $cause));
    }

    // --------------------------------------------------------------- commands

    private function handleCommands(): void
    {
        foreach (MonitorCommand::query()->where('status', 'pending')->orderBy('id')->get() as $cmd) {
            try {
                $result = match ($cmd->type) {
                    'new_session' => $this->rotateSession(),
                    'speed_test' => $this->runSpeedTest('manual'),
                    'reload' => $this->reload(),
                    default => 'unknown command',
                };
                $cmd->update(['status' => 'done', 'result' => $result]);
            } catch (\Throwable $e) {
                $cmd->update(['status' => 'failed', 'result' => $e->getMessage()]);
                $this->info('Command '.$cmd->type.' failed: '.$e->getMessage());
            }
        }
    }

    private function handleSchedule(): void
    {
        $minute = Carbon::now(config('monitor.display_timezone'))->format('H:i');
        if ($minute === $this->lastScheduleMinute) {
            return;
        }
        $this->lastScheduleMinute = $minute;
        if (in_array($minute, (array) $this->settings['speed_schedule'], true)) {
            $this->runSpeedTest('scheduled');
        }
    }

    private function reload(): string
    {
        $before = $this->settings['interface'] ?? null;
        $this->configure();
        if (($this->settings['interface'] ?? null) !== $before) {
            $this->inspector = new LinkInspector($this->settings['interface'] ?: null);
        }
        $this->chain->append('SettingsChanged', ['settings' => array_diff_key($this->settings, ['locale' => 1])], true);

        return 'settings reloaded';
    }

    private function rotateSession(): string
    {
        $this->closeSession();
        $this->lastMono = $this->lastWall = $this->lastState = null;
        $this->recent = [];
        $this->startSession();

        return $this->session->name;
    }

    private function runSpeedTest(string $trigger): string
    {
        $base = ['monitor_session_id' => $this->session->id, 'measured_at' => Carbon::now(), 'trigger' => $trigger,
            'contracted_down_mbps' => $this->settings['contracted_down_mbps'],
            'contracted_up_mbps' => $this->settings['contracted_up_mbps'], 'wireless' => $this->link?->wireless ?? false];

        // Measuring throughput on a line that is down would record the outage as a speed.
        if ($this->detector->hasOpenIncident() || $this->lastState?->isOutage()) {
            SpeedTestRow::query()->create($base + ['status' => 'failed', 'note' => 'Not measured: the connection was down at the scheduled time.']);

            return 'skipped: connection down';
        }

        $fromWall = microtime(true);
        $fromMono = $this->lastMono ?? $this->mono();
        $this->chain->append('SpeedTestStart', ['trigger' => $trigger, 'backend' => $this->settings['speed_backend'] ?? 'cloudflare'], true);
        $this->speedRunning = true;
        $this->writeStatus(NetworkState::SelfTest, null);

        $serverId = (int) ($this->settings['speedtest_server_id'] ?? 0) ?: null;
        $result = ($this->settings['speed_backend'] ?? 'cloudflare') === 'speedtest'
            ? (new SpeedtestCli((bool) ($this->settings['speedtest_accept_license'] ?? false), $serverId))->run((bool) $this->settings['speed_upload'])
            : ['backend' => 'cloudflare'] + (new SpeedTest((int) $this->settings['speed_duration_s']))->run((bool) $this->settings['speed_upload']);

        $this->speedRunning = false;
        SpeedTestRow::query()->create($base + $result);
        $this->chain->append('SpeedTest', $result + ['trigger' => $trigger, 'wireless' => $base['wireless']], true);

        // Our own test saturated the link on purpose: that stretch is excluded from the
        // assessment rather than written down as degradation.
        $this->recordGap($fromWall, microtime(true), 'SelfTest', $fromMono);
        $this->lastMono = $this->lastWall = null;

        return $result['status'];
    }

    // --------------------------------------------------------------- lifecycle

    private function closeSession(): void
    {
        $this->flushMinute();
        if (($open = $this->detector->closeOpenIncident()) !== null) {
            $this->finalizeIncident($open);
        }
        $this->chain->append('SessionEnd', [
            'monitored_ms' => $this->session->monitored_ms, 'samples' => $this->session->samples,
        ], true);
        $this->checkpoint();
        $this->session->update(['status' => 'closed', 'ended_at' => Carbon::now()]);
        $this->chain->close();
    }

    private function shutdown(): void
    {
        $this->flushMinute();
        if (($open = $this->detector->closeOpenIncident()) !== null) {
            $this->finalizeIncident($open);
        }
        $this->chain->append('MonitorStopped', ['samples' => $this->session->samples], true);
        $this->checkpoint();
        $this->chain->close();
        @unlink($this->statusPath());
        $this->info('Monitor stopped; session stays open and resumes on next start.');
    }

    private function writeStatus(NetworkState $state, ?array $deep): void
    {
        $open = $this->detector->open();
        $status = [
            'updated_at' => microtime(true),
            'pid' => getmypid(),
            'version' => self::VERSION,
            'state' => $state->value,
            'severity' => $state->severity(),
            'domain' => $state->domain($this->link?->wireless ?? false),
            'detail' => $this->detail,
            'since' => $this->stateSinceWall,
            'phase' => $this->cadence->phase,
            'interval_ms' => $this->cadence->interval(),
            'speed_running' => $this->speedRunning,
            'link' => $this->link?->toArray(),
            'icmp_method' => $this->fast->usesRawIcmp() ? 'socket' : 'ping-binary',
            'session' => [
                'id' => $this->session->id, 'name' => $this->session->name,
                'started_at' => $this->session->started_at->toIso8601String(),
                'monitored_ms' => $this->session->monitored_ms, 'samples' => $this->session->samples,
            ],
            'open_incident' => $open ? [
                'number' => $open['number'], 'state' => $open['worst_state']->value,
                'started' => $open['started_wall'], 'min_ms' => $open['duration_min_ms'],
            ] : null,
            'deep' => $deep ? [
                'fresh' => $deep['fresh'], 'http' => $deep['http']->results, 'tls' => $deep['tls']->results,
                'dns_assigned' => $deep['dnsAssigned']->results, 'dns_public' => $deep['dnsPublic']->results,
            ] : null,
            'last' => $this->lastSample,
            'recent' => $this->recent,
        ];
        $path = $this->statusPath();
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path.'.tmp', json_encode($status));
        @rename($path.'.tmp', $path);
    }

    public static function statusFile(): string
    {
        return rtrim(config('monitor.runtime_dir'), '/').'/status.json';
    }

    private function statusPath(): string
    {
        return self::statusFile();
    }

    private function sleepUntil(float $targetMono): void
    {
        while (! $this->stop && ($remaining = $targetMono - $this->mono()) > 0) {
            if ($this->deep->isRunning()) {
                $this->deep->poll();
                usleep((int) min(20_000, $remaining * 1000));
            } else {
                usleep((int) min(200_000, $remaining * 1000));
            }
        }
    }

    private function mono(): float
    {
        return hrtime(true) / 1e6;
    }

    private function carbon(float $unix): Carbon
    {
        return Carbon::createFromTimestampMs((int) round($unix * 1000));
    }

    private function info(string $message): void
    {
        if ($this->log) {
            ($this->log)($message);
        }
    }
}
