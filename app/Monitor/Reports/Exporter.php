<?php

namespace App\Monitor\Reports;

use App\Models\ComplaintCase;
use App\Models\MonitorSession;
use App\Models\Setting;
use App\Monitor\EvidenceChain;
use App\Monitor\Legal\ComplaintLetter;
use App\Support\Fmt;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use ZipArchive;

/** Renders a session as HTML, PDF, CSV and as one ZIP package ready to send. */
final class Exporter
{
    public static function html(MonitorSession $session, string $locale, bool $pdf = false, ?array $data = null): string
    {
        $data ??= ReportData::build($session);

        return view('report', [
            'd' => $data, 'locale' => $locale, 'pdf' => $pdf,
            'series' => ReportData::series($data['minutes']),
            'settings' => Setting::allMerged(),
        ])->render();
    }

    /**
     * The PDF is built directly rather than by printing the HTML from a browser:
     * a browser writes its own margins, scale and headers into the document, so
     * two people would send two different documents.
     */
    public static function pdf(MonitorSession $session, string $locale, ?array $data = null): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(self::html($session, $locale, true, $data), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    public static function outagesCsv(array $data): string
    {
        $rows = [['number', 'started_utc', 'ended_utc', 'duration_estimate_s', 'duration_min_s', 'duration_max_s',
            'worst_state', 'isolated_to', 'samples', 'confidence_support', 'confidence_coverage', 'ended_by_pause',
            'continued_after_pause', 'return_not_observed', 'peak_own_traffic_bytes_per_s', 'correlation_id', 'detail']];
        foreach ($data['outages'] as $i) {
            $rows[] = [$i->number, $i->started_at->toIso8601ZuluString('millisecond'), $i->ended_at?->toIso8601ZuluString('millisecond'),
                $i->duration_reported_ms / 1000, $i->duration_min_ms / 1000, $i->duration_max_ms / 1000,
                $i->worst_state, $i->domain, $i->sample_count, $i->confidence_support, $i->confidence_coverage,
                (int) $i->ended_by_gap, (int) $i->started_after_gap, (int) $i->is_open, $i->peak_local_bps, $i->correlation_id, $i->detail];
        }

        return self::csv($rows);
    }

    public static function measurementsCsv(array $data): string
    {
        $rows = [['minute_utc', 'samples', 'ok_samples', 'outage_samples', 'rtt_min_ms', 'rtt_avg_ms', 'rtt_max_ms',
            'gateway_rtt_avg_ms', 'jitter_avg_ms', 'worst_state']];
        foreach ($data['minutes'] as $m) {
            $rows[] = [$m->minute_at->toIso8601ZuluString(), $m->samples, $m->ok_samples, $m->outage_samples,
                $m->rtt_min, $m->rtt_avg, $m->rtt_max, $m->gateway_rtt_avg, $m->jitter_avg, $m->worst_state];
        }

        return self::csv($rows);
    }

    public static function gapsCsv(array $data): string
    {
        $rows = [['started_utc', 'ended_utc', 'duration_s', 'cause']];
        foreach ($data['gaps'] as $g) {
            $rows[] = [$g->started_at->toIso8601ZuluString(), $g->ended_at->toIso8601ZuluString(), $g->duration_ms / 1000, $g->cause];
        }

        return self::csv($rows);
    }

    public static function chainText(array $data, string $locale): string
    {
        $c = $data['chain'] ?? EvidenceChain::verify($data['session']->chain_path);
        $line = $c['valid']
            ? ($locale === 'sr' ? 'ISPRAVAN' : 'VALID')
            : ($locale === 'sr' ? 'NARUŠEN' : 'BROKEN')." (line {$c['first_bad_line']}: {$c['reason']})";

        return implode("\n", [
            'RawEvidence.jsonl: '.$line,
            'entries: '.$c['entries'],
            'last hash: '.$c['last_hash'],
            'checked: '.gmdate('Y-m-d\TH:i:s\Z'),
            '',
            Fmt::wrap(__('report.chain_explain', [], $locale)),
            '',
            'Verify independently: each line is JSON ending in ,"h":"<hash>"}. Remove that suffix, close the',
            'object with }, and the SHA-256 of the result must equal <hash>; the "prev" field must equal the',
            'hash of the line before it (64 zeros for the first line).',
            '',
        ]);
    }

    public static function summaryText(array $d, string $locale): string
    {
        $t = fn (string $k, array $r = []) => __('report.'.$k, $r, $locale);
        $s = $d['session'];

        return implode("\n", [
            $t('title'), str_repeat('=', mb_strlen($t('title'))), '',
            $t('session').': '.$s->name,
            $t('period').': '.Fmt::dateTime($s->started_at, $locale).' — '.Fmt::dateTime($s->ended_at ?? $s->last_sample_at, $locale),
            $t('monitored_time').': '.Fmt::duration($d['monitored_ms'], false),
            $t('paused_time').': '.Fmt::duration($d['gap_ms'], false),
            $t('outages_upstream').': '.$d['upstream']->count().' ('.Fmt::duration($d['upstream_ms']).')',
            $t('outages_all').': '.$d['outages']->count().' ('.Fmt::duration($d['outage_ms']).')',
            $t('availability_upstream').': '.Fmt::percent($d['upstream_availability'], $locale),
            $t('availability_all').': '.Fmt::percent($d['availability'], $locale),
            $t('evidence_quality').': '.$t('quality.'.$d['quality']),
            '', Fmt::wrap($t('verdict.'.$d['verdict'])), '', Fmt::wrap($t('limits_text')), '',
        ]);
    }

    /** Builds the ZIP package and returns its path. */
    public static function package(MonitorSession $session, string $locale): string
    {
        $data = ReportData::build($session);
        $files = [
            'Report.pdf' => self::pdf($session, $locale, $data),
            'Report.html' => self::html($session, $locale, false, $data),
            'Summary.txt' => self::summaryText($data, $locale),
            'Outages.csv' => self::outagesCsv($data),
            'Measurements.csv' => self::measurementsCsv($data),
            'MonitoringPauses.csv' => self::gapsCsv($data),
            'SpeedTests.json' => json_encode($data['speed_tests']->map->only([
                'measured_at', 'status', 'trigger', 'down_mbps', 'up_mbps', 'idle_ms', 'loaded_down_ms', 'loaded_up_ms',
                'latency_increase_ms', 'latency_grade', 'contracted_down_mbps', 'contracted_up_mbps', 'bytes_used', 'wireless', 'note', 'backend', 'server', 'result_url',
            ])->values(), JSON_PRETTY_PRINT),
            'RawEvidence.jsonl' => (string) file_get_contents($session->chain_path),
            'ChainVerification.txt' => self::chainText($data, $locale),
        ];

        if ($case = ComplaintCase::query()->where('monitor_session_id', $session->id)->first()) {
            $files[$locale === 'sr' ? 'Prigovor-operateru.txt' : 'Complaint-to-operator.txt'] = ComplaintLetter::toOperator($case, $data, $locale);
        }

        $sums = '';
        foreach ($files as $name => $content) {
            $sums .= hash('sha256', $content).'  '.$name."\n";
        }
        $files['SHA256SUMS.txt'] = $sums;

        $dir = rtrim(config('monitor.storage_dir'), '/').'/packages';
        @mkdir($dir, 0775, true);
        $path = $dir.'/Evidence_'.$session->name.'_'.$locale.'.zip';
        @unlink($path);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create evidence package.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private static function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }
}
