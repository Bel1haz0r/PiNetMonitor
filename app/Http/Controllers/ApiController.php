<?php

namespace App\Http\Controllers;

use App\Models\CaseJournalEntry;
use App\Models\ComplaintCase;
use App\Models\Gap;
use App\Models\Incident;
use App\Models\MinuteStat;
use App\Models\MonitorCommand;
use App\Models\MonitorSession;
use App\Models\Setting;
use App\Models\SpeedTest;
use App\Monitor\EvidenceChain;
use App\Monitor\Legal\ComplaintLetter;
use App\Monitor\Legal\Deadlines;
use App\Monitor\MonitorEngine;
use App\Monitor\Reports\Exporter;
use App\Monitor\Reports\ReportData;
use App\Monitor\Speed\SpeedtestCli;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ApiController extends Controller
{
    private function locale(Request $r): string
    {
        $locale = $r->query('lang') === 'sr' ? 'sr' : 'en';
        app()->setLocale($locale);

        return $locale;
    }

    // ------------------------------------------------------------------ live

    public function status()
    {
        $raw = @file_get_contents(MonitorEngine::statusFile());
        $status = $raw ? json_decode($raw, true) : null;
        $running = $status !== null && microtime(true) - ($status['updated_at'] ?? 0) < 15;
        $session = MonitorSession::query()->latest('id')->first();

        return response()->json([
            'running' => $running,
            'now' => microtime(true),
            'live' => $running ? $status : null,
            'session' => $session ? Cache::remember('summary:'.$session->id, 5, fn () => $this->summary($session)) : null,
            'pending_commands' => MonitorCommand::query()->where('status', 'pending')->pluck('type'),
        ]);
    }

    private function summary(MonitorSession $s): array
    {
        $d = ReportData::build($s, verifyChain: false, withMinutes: false);

        return [
            'id' => $s->id, 'name' => $s->name, 'status' => $s->status,
            'started_at' => $s->started_at->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
            'interface' => $s->interface, 'gateway' => $s->gateway, 'wireless' => $s->wireless,
            'monitored_ms' => $d['monitored_ms'], 'gap_ms' => $d['gap_ms'], 'gap_count' => $d['gaps']->count(),
            'samples' => $d['samples'], 'outage_count' => $d['outages']->count(), 'outage_ms' => $d['outage_ms'],
            'upstream_count' => $d['upstream']->count(), 'upstream_ms' => $d['upstream_ms'],
            'upstream_min_ms' => $d['upstream_min_ms'], 'upstream_max_ms' => $d['upstream_max_ms'],
            'availability' => $d['availability'], 'upstream_availability' => $d['upstream_availability'],
            'rtt_avg' => $d['rtt_avg'], 'rtt_min' => $d['rtt_min'], 'rtt_max' => $d['rtt_max'],
            'degraded_minutes' => $d['degraded_minutes'], 'verdict' => $d['verdict'], 'quality' => $d['quality'],
            'by_domain' => $d['by_domain'], 'chain_entries' => $s->chain_entries, 'last_hash' => $s->last_hash,
            'clock_events' => $d['clock_events']->count(),
        ];
    }

    // -------------------------------------------------------------- sessions

    public function sessions()
    {
        return MonitorSession::query()->latest('id')->get()->map(fn ($s) => $this->summary($s));
    }

    public function newSession()
    {
        MonitorCommand::query()->create(['type' => 'new_session']);

        return response()->json(['queued' => true]);
    }

    public function session(MonitorSession $session)
    {
        return $this->summary($session);
    }

    public function incidents(MonitorSession $session)
    {
        return Incident::query()->where('monitor_session_id', $session->id)->orderByDesc('started_at')->limit(500)->get();
    }

    public function gaps(MonitorSession $session)
    {
        return Gap::query()->where('monitor_session_id', $session->id)->orderByDesc('started_at')->limit(500)->get();
    }

    public function series(MonitorSession $session, Request $r)
    {
        $hours = (int) $r->query('hours', 24);
        $q = MinuteStat::query()->where('monitor_session_id', $session->id)->orderBy('minute_at');
        if ($hours > 0) {
            $q->where('minute_at', '>=', now()->subHours($hours));
        }

        return ReportData::series($q->get(), 360);
    }

    public function verify(MonitorSession $session)
    {
        return EvidenceChain::verify($session->chain_path);
    }

    public function export(MonitorSession $session, string $what, Request $r)
    {
        $locale = $this->locale($r);
        $name = $session->name;
        $download = fn (string $body, string $file, string $type) => response($body, 200, [
            'Content-Type' => $type, 'Content-Disposition' => ($r->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$file.'"',
        ]);

        return match ($what) {
            'report.html' => response(Exporter::html($session, $locale)),
            'report.pdf' => $download(Exporter::pdf($session, $locale), "Report_$name.pdf", 'application/pdf'),
            'outages.csv' => $download(Exporter::outagesCsv(ReportData::build($session, false)), "Outages_$name.csv", 'text/csv'),
            'measurements.csv' => $download(Exporter::measurementsCsv(ReportData::build($session, false)), "Measurements_$name.csv", 'text/csv'),
            'raw.jsonl' => response()->download($session->chain_path, "RawEvidence_$name.jsonl"),
            'package.zip' => response()->download(Exporter::package($session, $locale)),
            default => abort(404),
        };
    }

    // -------------------------------------------------------------- settings

    public function settings()
    {
        return Setting::allMerged();
    }

    public function saveSettings(Request $r)
    {
        $data = $r->validate([
            'interface' => 'nullable|string|max:32|regex:/^[A-Za-z0-9_.:-]+$/',
            'icmp_targets' => 'array|min:1|max:8', 'icmp_targets.*' => 'ipv4',
            'tcp_targets' => 'array|min:1|max:8', 'tcp_targets.*' => ['regex:/^\d{1,3}(\.\d{1,3}){3}:\d{1,5}$/'],
            'dns_public' => 'array|min:1|max:4', 'dns_public.*' => 'ipv4',
            'interval_stable_ms' => 'integer|min:500|max:60000', 'interval_suspect_ms' => 'integer|min:100|max:10000',
            'interval_burst_ms' => 'integer|min:100|max:10000', 'interval_incident_ms' => 'integer|min:100|max:10000',
            'interval_recovery_ms' => 'integer|min:100|max:10000',
            'probe_timeout_ms' => 'integer|min:200|max:5000', 'probe_timeout_fast_ms' => 'integer|min:100|max:5000',
            'deep_interval_s' => 'integer|min:10|max:3600',
            'high_latency_ms' => 'numeric|min:20|max:5000', 'high_jitter_ms' => 'numeric|min:5|max:5000',
            'gap_threshold_s' => 'integer|min:5|max:600',
            'contracted_down_mbps' => 'nullable|numeric|min:0', 'contracted_up_mbps' => 'nullable|numeric|min:0',
            'speed_upload' => 'boolean', 'speed_schedule' => 'array|max:12', 'speed_schedule.*' => ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'speed_duration_s' => 'integer|min:3|max:30',
            'speed_backend' => 'in:cloudflare,speedtest', 'speedtest_accept_license' => 'boolean',
            'speedtest_server_id' => 'nullable|integer|min:1',
            'locale' => 'in:en,sr',
        ]);
        Setting::put($data);
        MonitorCommand::query()->create(['type' => 'reload']);

        return Setting::allMerged();
    }

    // ----------------------------------------------------------------- speed

    public function speedTests()
    {
        return SpeedTest::query()->orderByDesc('measured_at')->limit(200)->get();
    }

    /** Which speedtest command, if any, is installed on this device. */
    public function speedTool()
    {
        return ['tool' => SpeedtestCli::detect()];
    }

    public function runSpeed()
    {
        if (! MonitorCommand::query()->where('type', 'speed_test')->where('status', 'pending')->exists()) {
            MonitorCommand::query()->create(['type' => 'speed_test']);
        }

        return response()->json(['queued' => true]);
    }

    // ------------------------------------------------------------------ case

    private function currentCase(): ComplaintCase
    {
        return ComplaintCase::query()->latest('id')->first()
            ?? ComplaintCase::query()->create(['monitor_session_id' => MonitorSession::query()->latest('id')->value('id')]);
    }

    private function casePayload(ComplaintCase $case): array
    {
        $session = $case->monitor_session_id ? MonitorSession::query()->find($case->monitor_session_id) : null;
        $data = $session ? ReportData::build($session, false) : null;
        $tz = config('monitor.display_timezone');
        // The complaint period runs from the day the service could not be used: the
        // most recent outage isolated beyond the router, unless a date was entered.
        $event = $data && $data['upstream']->count()
            ? CarbonImmutable::parse($data['upstream']->max('started_at'))->setTimezone($tz) : null;
        $milestones = Deadlines::build($case, $event, CarbonImmutable::now($tz));

        return [
            'case' => $case,
            'derived_event_date' => $event?->toDateString(),
            'milestones' => $milestones,
            'next_action' => Deadlines::nextAction($milestones),
            'journal' => CaseJournalEntry::query()->where('complaint_case_id', $case->id)->orderByDesc('entry_date')->orderByDesc('id')->get(),
            'upstream_count' => $data ? $data['upstream']->count() : 0,
        ];
    }

    public function case()
    {
        return $this->casePayload($this->currentCase());
    }

    public function saveCase(Request $r)
    {
        $data = $r->validate([
            'monitor_session_id' => 'nullable|exists:monitor_sessions,id',
            'operator_name' => 'nullable|string|max:200', 'subscriber_name' => 'nullable|string|max:200',
            'contract_number' => 'nullable|string|max:100', 'service_address' => 'nullable|string|max:300',
            'contact_phone' => 'nullable|string|max:60', 'contact_email' => 'nullable|email|max:200',
            'customer_type' => 'in:consumer,non_consumer',
            'event_date' => 'nullable|date', 'submitted_at' => 'nullable|date', 'responded_at' => 'nullable|date',
            'outcome' => 'nullable|in:accepted,rejected', 'regulator_filed_at' => 'nullable|date',
        ]);
        $case = $this->currentCase();
        $before = $case->only(['submitted_at', 'responded_at', 'outcome', 'regulator_filed_at']);
        $case->update($data);

        // The diary records each procedural step as it is entered.
        foreach (['submitted_at' => 'submitted', 'responded_at' => 'responded', 'regulator_filed_at' => 'regulator_filed'] as $field => $kind) {
            if ($case->$field && (string) ($before[$field] ?? '') !== (string) $case->$field) {
                CaseJournalEntry::query()->create(['complaint_case_id' => $case->id, 'entry_date' => $case->$field,
                    'kind' => $kind, 'text' => $kind === 'responded' && $case->outcome ? $case->outcome : '']);
            }
        }

        return $this->casePayload($case->fresh());
    }

    public function newCase()
    {
        $case = ComplaintCase::query()->create(['monitor_session_id' => MonitorSession::query()->latest('id')->value('id')]);

        return $this->casePayload($case);
    }

    public function addJournal(Request $r)
    {
        $data = $r->validate(['entry_date' => 'required|date', 'text' => 'required|string|max:2000']);
        $case = $this->currentCase();
        CaseJournalEntry::query()->create($data + ['complaint_case_id' => $case->id, 'kind' => 'note']);

        return $this->casePayload($case);
    }

    public function letter(Request $r, string $kind)
    {
        $case = $this->currentCase();
        $session = $case->monitor_session_id ? MonitorSession::query()->find($case->monitor_session_id) : null;
        if ($session === null) {
            return response()->json(['ready' => false, 'reason' => 'No session is attached to the case.', 'text' => null]);
        }
        $data = ReportData::build($session, false);
        $locale = $this->locale($r);

        return $kind === 'regulator'
            ? ComplaintLetter::toRegulator($case, $data, $locale)
            : ['ready' => true, 'reason' => null, 'text' => ComplaintLetter::toOperator($case, $data, $locale)];
    }
}
