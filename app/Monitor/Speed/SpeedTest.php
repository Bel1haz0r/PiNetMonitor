<?php

namespace App\Monitor\Speed;

/**
 * Measures download, upload and — the part an ordinary speed test skips —
 * latency while the link is loaded, compared with latency while it idles.
 *
 * A connection that reaches its contracted speed can still be unusable for
 * calls and games: an oversized buffer somewhere on the path fills under load
 * and everything behind it waits. That shows in the round trip DURING the
 * transfer, not in the throughput figure.
 *
 * Each direction saturates the link with several parallel connections for a
 * fixed window. It costs as much data as the link can move in that time.
 */
class SpeedTest
{
    private const DOWN_URL = 'https://speed.cloudflare.com/__down?bytes=';

    private const UP_URL = 'https://speed.cloudflare.com/__up';

    private const PROBE_URL = 'https://speed.cloudflare.com/__down?bytes=0';

    public const NOTICEABLE_MS = 30.0;

    public const SEVERE_MS = 100.0;

    public function __construct(
        private readonly int $durationSec = 10,
        private readonly int $streams = 3,
        private readonly float $settleSec = 1.5,
    ) {}

    /** @return array<string,mixed> row for the speed_tests table */
    public function run(bool $upload = true): array
    {
        $out = ['status' => 'ok', 'note' => null, 'bytes_used' => 0, 'down_mbps' => null, 'up_mbps' => null,
            'idle_ms' => null, 'loaded_down_ms' => null, 'loaded_up_ms' => null,
            'latency_increase_ms' => null, 'latency_grade' => null];

        $idle = $this->measure(null, 2.0);
        $out['idle_ms'] = $idle['latency'];
        if ($idle['latency'] === null) {
            return ['status' => 'failed', 'note' => 'The measurement server could not be reached.'] + $out;
        }

        $down = $this->measure('down', (float) $this->durationSec);
        $out['bytes_used'] += $down['bytes'];
        $out['down_mbps'] = $down['mbps'];
        $out['loaded_down_ms'] = $down['latency'];
        if ($down['mbps'] === null) {
            return ['status' => 'failed', 'note' => self::failure('Download', $down['refused'])] + $out;
        }

        if ($upload) {
            $up = $this->measure('up', (float) $this->durationSec);
            $out['bytes_used'] += $up['bytes'];
            $out['up_mbps'] = $up['mbps'];
            $out['loaded_up_ms'] = $up['latency'];
            if ($up['mbps'] === null) {
                $out['note'] = self::failure('Upload', $up['refused']);
            }
        }

        $increases = array_filter([
            $out['loaded_down_ms'] !== null ? $out['loaded_down_ms'] - $out['idle_ms'] : null,
            $out['loaded_up_ms'] !== null ? $out['loaded_up_ms'] - $out['idle_ms'] : null,
        ], fn ($v) => $v !== null);

        if ($increases) {
            $worst = round(max($increases), 1);
            $out['latency_increase_ms'] = $worst;
            $out['latency_grade'] = self::grade($worst);
        }

        return $out;
    }

    private static function failure(string $direction, ?int $refused): string
    {
        return $refused !== null
            ? "$direction not measured: the measurement server refused the transfer (HTTP $refused".($refused === 429 ? ', rate limit — try again later' : '').').'
            : "$direction measurement did not transfer enough data to state a speed.";
    }

    public static function grade(float $increaseMs): string
    {
        return $increaseMs >= self::SEVERE_MS ? 'severe' : ($increaseMs >= self::NOTICEABLE_MS ? 'noticeable' : 'slight');
    }

    /**
     * Runs one phase: optional load in one direction plus latency probes
     * every 250 ms on a separate connection.
     *
     * @return array{mbps: float|null, bytes: int, latency: float|null, refused: int|null}
     */
    private function measure(?string $direction, float $seconds): array
    {
        $multi = curl_multi_init();
        $counters = [];           // handle id => bytes moved by the current transfer
        $finishedBytes = 0;       // bytes of transfers already completed
        $load = [];
        $sink = fopen('/dev/null', 'w');
        $chunk = str_repeat('0', 65536);

        $addLoad = function () use (&$load, &$counters, $multi, $direction, $sink, $chunk) {
            $h = curl_init();
            $id = spl_object_id($h);
            $counters[$id] = 0;
            $opts = [
                CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_FILE => $sink,
                CURLOPT_USERAGENT => 'PiNetMonitor',
                // HTTP/1.1 keeps every stream and the latency probe on its own TCP connection.
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ];
            if ($direction === 'down') {
                $opts[CURLOPT_URL] = self::DOWN_URL.'25000000';
                $opts[CURLOPT_XFERINFOFUNCTION] = function ($ch, $dlTotal, $dlNow) use (&$counters, $id) {
                    $counters[$id] = $dlNow;

                    return 0;
                };
            } else {
                $size = 25_000_000;
                $sent = 0;
                $opts[CURLOPT_URL] = self::UP_URL;
                $opts[CURLOPT_UPLOAD] = true;
                $opts[CURLOPT_CUSTOMREQUEST] = 'POST';
                $opts[CURLOPT_INFILESIZE] = $size;
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/octet-stream', 'Expect:'];
                $opts[CURLOPT_READFUNCTION] = function ($ch, $fd, $length) use (&$sent, $size, $chunk) {
                    $n = min($length, $size - $sent, strlen($chunk));
                    $sent += $n;

                    return $n > 0 ? substr($chunk, 0, $n) : '';
                };
                $opts[CURLOPT_XFERINFOFUNCTION] = function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$counters, $id) {
                    $counters[$id] = $ulNow;

                    return 0;
                };
            }
            curl_setopt_array($h, $opts);
            curl_multi_add_handle($multi, $h);
            $load[$id] = $h;
        };

        $probe = null;
        $probes = [];
        $lastProbeAt = 0.0;
        $newProbe = function () use ($multi, &$probe) {
            $probe = curl_init(self::PROBE_URL);
            curl_setopt_array($probe, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_USERAGENT => 'PiNetMonitor',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
            curl_multi_add_handle($multi, $probe);
        };

        if ($direction !== null) {
            for ($i = 0; $i < $this->streams; $i++) {
                $addLoad();
            }
        }

        $start = microtime(true);
        $refused = null;
        $firstByteAt = null;
        $settleBytes = null;
        $settleAt = null;
        $total = function () use (&$finishedBytes, &$counters) {
            return $finishedBytes + array_sum($counters);
        };

        while (($elapsed = microtime(true) - $start) < $seconds) {
            if ($probe === null && microtime(true) - $lastProbeAt >= 0.25) {
                $newProbe();
                $lastProbeAt = microtime(true);
            }

            curl_multi_exec($multi, $active);
            curl_multi_select($multi, 0.02);

            while ($info = curl_multi_info_read($multi)) {
                $h = $info['handle'];
                $id = spl_object_id($h);
                if ($h === $probe) {
                    if ($info['result'] === CURLE_OK && curl_getinfo($h, CURLINFO_RESPONSE_CODE) === 200) {
                        // Request-to-first-byte on an established connection: excludes TCP/TLS setup.
                        $ms = (curl_getinfo($h, CURLINFO_STARTTRANSFER_TIME) - curl_getinfo($h, CURLINFO_PRETRANSFER_TIME)) * 1000;
                        // Skip the warm-up probe when idle, and the ramp-up when loaded.
                        if ($elapsed >= ($direction === null ? 0.5 : $this->settleSec)) {
                            $probes[] = $ms;
                        }
                    }
                    curl_multi_remove_handle($multi, $h);
                    $probe = null;
                } elseif (isset($load[$id])) {
                    $finishedBytes += $counters[$id];
                    unset($counters[$id], $load[$id]);
                    curl_multi_remove_handle($multi, $h);
                    // A transfer finished before the window closed: keep the link loaded. A refused
                    // one (the server rate-limits heavy use) is not retried.
                    $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
                    if ($info['result'] === CURLE_OK && $code === 200) {
                        $addLoad();
                    } elseif ($code >= 400) {
                        $refused = $code;
                    }
                }
            }

            if ($firstByteAt === null && $total() > 0) {
                $firstByteAt = microtime(true);
            }
            if ($direction !== null && $settleBytes === null && $elapsed >= $this->settleSec) {
                $settleBytes = $total();
                $settleAt = microtime(true);
            }
            if ($direction !== null && ! $load) {
                break; // every stream failed
            }
        }

        $end = microtime(true);
        $bytes = $total();
        foreach ($load as $h) {
            curl_multi_remove_handle($multi, $h);
        }
        if ($probe !== null) {
            curl_multi_remove_handle($multi, $probe);
        }
        curl_multi_close($multi);
        fclose($sink);

        // Throughput over the steady part of the window. A figure is only stated when data
        // was still moving after the ramp-up; a transfer the server cut short is not a speed.
        $mbps = null;
        if ($direction !== null && $refused === null && $settleBytes !== null && $end - $settleAt > 0.5 && $bytes - $settleBytes > 0) {
            $mbps = round((($bytes - $settleBytes) * 8) / ($end - $settleAt) / 1e6, 2);
        }

        sort($probes);
        $latency = $probes ? round($probes[(int) floor((count($probes) - 1) / 2)], 1) : null;

        return ['mbps' => $mbps, 'bytes' => (int) $bytes, 'latency' => $latency, 'refused' => $refused];
    }
}
