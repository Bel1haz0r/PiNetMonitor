@php
    use App\Support\Fmt;
    use App\Monitor\Reports\Charts;
    $t = fn (string $key, array $r = []) => __('report.'.$key, $r, $locale);
    $s = $d['session'];
    $tz = config('monitor.display_timezone');
    $kbps = fn ($bps) => $bps >= 1_000_000 ? Fmt::number($bps / 1_000_000, $locale).' MB/s' : Fmt::number($bps / 1000, $locale, 0).' kB/s';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $t('title') }} — {{ $s->name }}</title>
<style>
    @page { margin: 16mm 14mm; }
    body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: {{ $pdf ? '9.5pt' : '14px' }}; color: #1c2330; line-height: 1.45; {{ $pdf ? '' : 'max-width: 860px; margin: 24px auto; padding: 0 16px;' }} }
    h1 { font-size: 1.6em; margin: 0 0 2px; }
    h2 { font-size: 1.15em; margin: 22px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #1f5f99; }
    .muted { color: #5b6475; }
    .small { font-size: 0.88em; }
    table { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 4px 8px 4px 0; vertical-align: top; border-bottom: 1px solid #e5e8ee; }
    table.kv td.k { width: 44%; color: #5b6475; }
    table.data th { text-align: left; font-size: 0.85em; color: #5b6475; border-bottom: 1.5px solid #9aa3b2; padding: 4px 6px 4px 0; }
    table.data td { padding: 4px 6px 4px 0; border-bottom: 1px solid #e5e8ee; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .verdict { padding: 10px 12px; margin: 12px 0; border-left: 5px solid #8a94a6; background: #f3f5f8; }
    .verdict.upstream { border-color: #d64545; background: #fbeeee; }
    .verdict.clean { border-color: #2e9e6b; background: #edf7f2; }
    .verdict.local_only { border-color: #d99a1c; background: #fbf5e6; }
    .sw { display: inline-block; width: 10px; height: 10px; margin-right: 4px; }
    .legend span.item { margin-right: 14px; white-space: nowrap; }
    img.chart { width: 100%; height: auto; }
    .mono { font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace; font-size: 0.85em; word-break: break-all; }
    .bad { color: #b02a2a; font-weight: bold; }
    .good { color: #1d7a50; font-weight: bold; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
</style>
</head>
<body>
    <h1>{{ $t('title') }}</h1>
    <div class="muted">{{ $t('session') }} {{ $s->name }} · {{ $t('generated') }} {{ Fmt::dateTime($d['generated_at'], $locale) }} ({{ $tz }}) · PiNetMonitor {{ \App\Monitor\MonitorEngine::VERSION }}</div>

    <div class="verdict {{ $d['verdict'] }}">{{ $t('verdict.'.$d['verdict']) }}</div>

    <h2>{{ $t('summary') }}</h2>
    <table class="kv">
        <tr><td class="k">{{ $t('period') }}</td><td>{{ Fmt::dateTime($s->started_at, $locale) }} — {{ Fmt::dateTime($s->ended_at ?? $s->last_sample_at, $locale) }}</td></tr>
        <tr><td class="k">{{ $t('device') }}</td><td>{{ php_uname('n') }} · {{ $t('interface') }} {{ $s->interface ?? '—' }}, {{ $t($s->wireless ? 'wireless' : 'wired') }} · {{ $t('gateway') }} {{ $s->gateway ?? '—' }}</td></tr>
        <tr><td class="k">{{ $t('monitored_time') }}</td><td><b>{{ Fmt::duration($d['monitored_ms'], false) }}</b></td></tr>
        <tr><td class="k">{{ $t('paused_time') }}</td><td>{{ Fmt::duration($d['gap_ms'], false) }} ({{ $d['gaps']->count() }})</td></tr>
        <tr><td class="k">{{ $t('samples') }}</td><td>{{ number_format($d['samples'], 0, '', ' ') }}</td></tr>
        <tr><td class="k">{{ $t('outages_upstream') }}</td><td><b>{{ $d['upstream']->count() }}</b>@if($d['upstream']->count()), {{ $t('total_duration') }} <b>{{ Fmt::duration($d['upstream_ms']) }}</b> ({{ $t('bounds', ['min' => Fmt::duration($d['upstream_min_ms']), 'max' => Fmt::duration($d['upstream_max_ms'])]) }})@endif</td></tr>
        <tr><td class="k">{{ $t('outages_all') }}</td><td>{{ $d['outages']->count() }}@if($d['outages']->count()), {{ $t('total_duration') }} {{ Fmt::duration($d['outage_ms']) }}@endif</td></tr>
        <tr><td class="k">{{ $t('availability_upstream') }}</td><td><b>{{ Fmt::percent($d['upstream_availability'], $locale) }}</b></td></tr>
        <tr><td class="k">{{ $t('availability_all') }}</td><td>{{ Fmt::percent($d['availability'], $locale) }}</td></tr>
        @if($d['rtt_avg'] !== null)
        <tr><td class="k">{{ $t('latency') }}</td><td>{{ $t('latency_values', ['avg' => Fmt::number($d['rtt_avg'], $locale), 'min' => Fmt::number($d['rtt_min'], $locale), 'max' => Fmt::number($d['rtt_max'], $locale)]) }}</td></tr>
        @endif
        <tr><td class="k">{{ $t('degraded_minutes') }}</td><td>{{ $d['degraded_minutes'] }}</td></tr>
        <tr><td class="k">{{ $t('evidence_quality') }}</td><td>{{ $t('quality.'.$d['quality']) }}</td></tr>
    </table>

    @if(count($series))
    <h2>{{ $t('timeline') }}</h2>
    <img class="chart" src="{{ Charts::dataUri(Charts::timeline($series)) }}" alt="">
    <div class="legend small muted" style="margin-top:4px">
        @foreach(['ok', 'degraded', 'outage', 'none'] as $k)
            <span class="item"><span class="sw" style="background: {{ Charts::COLORS[$k] }}"></span>{{ $t('legend.'.$k) }}</span>
        @endforeach
    </div>
    <p class="small muted" style="margin-bottom:2px">{{ $t('latency_chart') }}</p>
    <img class="chart" src="{{ Charts::dataUri(Charts::latency($series)) }}" alt="">
    @endif

    <h2>{{ $t('outage_table') }}</h2>
    @if($d['outages']->isEmpty())
        <p>{{ $t('no_outages') }}</p>
    @else
    <table class="data">
        <thead><tr>
            <th>{{ $t('col.no') }}</th><th>{{ $t('col.start') }}</th><th class="num">{{ $t('col.duration') }}</th>
            <th class="num">{{ $t('col.min') }}</th><th class="num">{{ $t('col.max') }}</th>
            <th>{{ $t('col.isolated') }}</th><th class="num">{{ $t('col.confidence') }}</th><th>{{ $t('col.note') }}</th>
        </tr></thead>
        <tbody>
        @foreach($d['outages'] as $i)
            @php
                $notes = [];
                if ($i->ended_by_gap) $notes[] = $t('note.ended_by_gap');
                if ($i->started_after_gap) $notes[] = $t('note.started_after_gap');
                if ($i->is_open && ! $i->ended_by_gap) $notes[] = $t('note.open');
                if ($i->peak_local_bps !== null && $i->peak_local_bps >= 1_000_000) $notes[] = $t('note.own_traffic', ['rate' => $kbps($i->peak_local_bps)]);
            @endphp
            <tr>
                <td>{{ $i->number }}</td>
                <td>{{ Fmt::dateTime($i->started_at, $locale) }}</td>
                <td class="num"><b>{{ Fmt::duration($i->duration_reported_ms) }}</b></td>
                <td class="num">{{ Fmt::duration($i->duration_min_ms) }}</td>
                <td class="num">{{ Fmt::duration($i->duration_max_ms) }}</td>
                <td>{{ $t('domain.'.$i->domain) }}<br><span class="small muted">{{ $t('state.'.$i->worst_state) }}</span></td>
                <td class="num">@if($i->confidence_support !== null){{ $i->confidence_support }}% / {{ $i->confidence_coverage }}%<br><span class="small muted">{{ $t('quality.'.$i->confidence_band) }}</span>@else—@endif</td>
                <td class="small">{{ implode('; ', $notes) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <h2>{{ $t('gaps') }}</h2>
    @if($d['gaps']->isEmpty())
        <p>{{ $t('no_gaps') }}</p>
    @else
    <table class="data">
        <thead><tr><th>{{ $t('col.start') }}</th><th>{{ $t('col.end') }}</th><th class="num">{{ $t('col.duration') }}</th><th>{{ $t('col.cause') }}</th></tr></thead>
        <tbody>
        @foreach($d['gaps'] as $g)
            <tr><td>{{ Fmt::dateTime($g->started_at, $locale) }}</td><td>{{ Fmt::dateTime($g->ended_at, $locale) }}</td>
                <td class="num">{{ Fmt::duration($g->duration_ms, false) }}</td><td>{{ $t('gap_cause.'.$g->cause) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif
    @if($d['clock_events']->count())
        <p class="small muted">{{ $t('clock_note', ['count' => $d['clock_events']->count()]) }}</p>
    @endif

    <h2>{{ $t('speed') }}</h2>
    @if($d['speed_tests']->isEmpty())
        <p>{{ $t('speed_none') }}</p>
    @else
    <table class="data">
        <thead><tr><th>{{ $t('col.time') }}</th><th class="num">{{ $t('col.download') }}</th><th class="num">{{ $t('col.upload') }}</th>
            <th class="num">{{ $t('col.contract') }}</th><th class="num">{{ $t('col.idle') }}</th><th class="num">{{ $t('col.loaded') }}</th><th class="num">{{ $t('col.increase') }}</th></tr></thead>
        <tbody>
        @foreach($d['speed_tests'] as $sp)
            <tr>
                <td>{{ Fmt::dateTime($sp->measured_at, $locale) }}<br><span class="small muted">{{ $t('backend.'.($sp->backend ?? 'cloudflare')) }}@if($sp->server) — {{ $sp->server }}@endif</span>@if($sp->status !== 'ok')<br><span class="small bad">{{ $sp->note }}</span>@endif</td>
                <td class="num">{{ $sp->down_mbps !== null ? Fmt::number($sp->down_mbps, $locale).' Mbit/s' : '—' }}</td>
                <td class="num">{{ $sp->up_mbps !== null ? Fmt::number($sp->up_mbps, $locale).' Mbit/s' : '—' }}</td>
                <td class="num">{{ $sp->contracted_down_mbps ? Fmt::number($sp->contracted_down_mbps, $locale, 0).($sp->contracted_up_mbps ? '/'.Fmt::number($sp->contracted_up_mbps, $locale, 0) : '') : '—' }}</td>
                <td class="num">{{ $sp->idle_ms !== null ? Fmt::number($sp->idle_ms, $locale).' ms' : '—' }}</td>
                <td class="num">{{ $sp->loaded_down_ms !== null ? Fmt::number(max($sp->loaded_down_ms, $sp->loaded_up_ms ?? 0), $locale).' ms' : '—' }}</td>
                <td class="num">@if($sp->latency_increase_ms !== null)+{{ Fmt::number($sp->latency_increase_ms, $locale) }} ms<br><span class="small muted">{{ $t('grade.'.$sp->latency_grade) }}</span>@else—@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="small muted">{{ $t('speed_limits') }}</p>
    @endif

    <h2>{{ $t('integrity') }}</h2>
    @if($d['chain'])
        @if($d['chain']['valid'])
            <p><span class="good">✓</span> {!! $t('chain_valid', ['n' => $d['chain']['entries'], 'hash' => '<span class="mono">'.$d['chain']['last_hash'].'</span>']) !!}</p>
        @else
            <p class="bad">{{ $t('chain_broken', ['line' => $d['chain']['first_bad_line'], 'reason' => $d['chain']['reason']]) }}</p>
        @endif
    @endif
    <p class="small muted">{{ $t('chain_explain') }}</p>

    <h2>{{ $t('method') }}</h2>
    <p class="small">{{ $t('method_text', ['icmp' => count($settings['icmp_targets']), 'tcp' => count($settings['tcp_targets'])]) }}</p>
    <h2>{{ $t('limits') }}</h2>
    <p class="small">{{ $t('limits_text') }}</p>
</body>
</html>
