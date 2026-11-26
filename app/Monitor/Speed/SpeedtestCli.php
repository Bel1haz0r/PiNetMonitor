<?php

namespace App\Monitor\Speed;

/**
 * Alternative speed measurement through an installed `speedtest` command.
 *
 * Two different programs go by that name and both are supported:
 *  - Ookla's official CLI ("ookla"): picks a nearby server, and since 1.2
 *    reports latency during the download and the upload as well as idle;
 *  - the open-source speedtest-cli ("sivel", packaged by Debian): throughput
 *    and idle ping only, so no latency-under-load figure.
 *
 * Compared with the built-in measurement it tests against a server close to
 * the line (often inside the operator's own network), which is the figure
 * people usually quote. Ookla's CLI is proprietary: its licence has to be
 * accepted, and every result is uploaded to speedtest.net.
 */
class SpeedtestCli
{
    private const NAMES = ['speedtest', 'speedtest-cli'];

    private const EXTRA_DIRS = ['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin', '/snap/bin'];

    public function __construct(
        private readonly bool $acceptLicense = false,
        private readonly ?int $serverId = null,
        private readonly int $timeoutSec = 120,
    ) {}

    /**
     * Finds an installed command and tells which program it is.
     *
     * @return array{kind: string, path: string, version: string}|null
     */
    public static function detect(): ?array
    {
        $dirs = array_unique(array_filter(array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), self::EXTRA_DIRS)));
        foreach (self::NAMES as $name) {
            foreach ($dirs as $dir) {
                $path = rtrim($dir, '/').'/'.$name;
                if (! is_file($path) || ! is_executable($path)) {
                    continue;
                }
                [, $out, $err] = self::exec([$path, '--version'], 8);
                $text = trim($out."\n".$err);
                $first = trim(strtok($text, "\n") ?: '');
                if (stripos($text, 'Ookla') !== false) {
                    return ['kind' => 'ookla', 'path' => $path, 'version' => $first];
                }
                if (stripos($text, 'speedtest-cli') !== false) {
                    return ['kind' => 'sivel', 'path' => $path, 'version' => $first];
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed> row for the speed_tests table */
    public function run(bool $upload = true): array
    {
        $tool = self::detect();
        if ($tool === null) {
            return self::failed('No speedtest command is installed on this device. Install Ookla\'s speedtest or the speedtest-cli package, or switch back to the built-in measurement.');
        }

        if ($tool['kind'] === 'ookla') {
            if (! $this->acceptLicense) {
                return self::failed('Ookla\'s licence and privacy terms have not been accepted in Settings, so the command was not run.', $tool);
            }
            // No switch to skip the upload exists in this program; it always measures both.
            $cmd = [$tool['path'], '--format=json', '--progress=no', '--accept-license', '--accept-gdpr'];
            if ($this->serverId) {
                $cmd[] = '--server-id='.$this->serverId;
            }
        } else {
            $cmd = [$tool['path'], '--json', '--secure'];
            if (! $upload) {
                $cmd[] = '--no-upload';
            }
            if ($this->serverId) {
                array_push($cmd, '--server', (string) $this->serverId);
            }
        }

        [$code, $out, $err, $timedOut] = self::exec($cmd, $this->timeoutSec);
        if ($timedOut) {
            return self::failed("The speedtest command did not finish within {$this->timeoutSec} s and was stopped.", $tool);
        }

        $row = self::parse($tool['kind'], $out);
        if ($row === null) {
            return self::failed('The speedtest command failed'.($code ? " (exit $code)" : '').': '.self::errorText($err ?: $out), $tool);
        }

        return ['backend' => $tool['kind']] + $row;
    }

    /**
     * Turns the program's JSON into a result row; null when it holds no result.
     *
     * @return array<string,mixed>|null
     */
    public static function parse(string $kind, string $output): ?array
    {
        $data = null;
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $j = json_decode($line, true);
            if (is_array($j) && ($kind === 'sivel' ? isset($j['download']) : ($j['type'] ?? null) === 'result')) {
                $data = $j;
            }
        }
        if ($data === null) {
            return null;
        }

        $row = ['status' => 'ok', 'note' => null, 'bytes_used' => 0, 'down_mbps' => null, 'up_mbps' => null,
            'idle_ms' => null, 'loaded_down_ms' => null, 'loaded_up_ms' => null,
            'latency_increase_ms' => null, 'latency_grade' => null, 'server' => null, 'result_url' => null];
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;

        if ($kind === 'ookla') {
            // "bandwidth" is bytes per second.
            $down = $num($data['download']['bandwidth'] ?? null);
            $up = $num($data['upload']['bandwidth'] ?? null);
            $row['down_mbps'] = $down ? round($down * 8 / 1e6, 2) : null;
            $row['up_mbps'] = $up ? round($up * 8 / 1e6, 2) : null;
            $row['idle_ms'] = ($v = $num($data['ping']['latency'] ?? null)) !== null ? round($v, 1) : null;
            $row['loaded_down_ms'] = ($v = $num($data['download']['latency']['iqm'] ?? null)) !== null ? round($v, 1) : null;
            $row['loaded_up_ms'] = ($v = $num($data['upload']['latency']['iqm'] ?? null)) !== null ? round($v, 1) : null;
            $row['bytes_used'] = (int) ($data['download']['bytes'] ?? 0) + (int) ($data['upload']['bytes'] ?? 0);
            $s = $data['server'] ?? [];
            $row['server'] = self::label([$s['name'] ?? null, $s['location'] ?? null], $s['id'] ?? null);
            $row['result_url'] = is_string($data['result']['url'] ?? null) ? $data['result']['url'] : null;
        } else {
            // "download" / "upload" are bits per second.
            $down = $num($data['download'] ?? null);
            $up = $num($data['upload'] ?? null);
            $row['down_mbps'] = $down ? round($down / 1e6, 2) : null;
            $row['up_mbps'] = $up ? round($up / 1e6, 2) : null;
            $row['idle_ms'] = ($v = $num($data['ping'] ?? null)) !== null ? round($v, 1) : null;
            $row['bytes_used'] = (int) ($data['bytes_received'] ?? 0) + (int) ($data['bytes_sent'] ?? 0);
            $s = $data['server'] ?? [];
            $row['server'] = self::label([$s['sponsor'] ?? null, $s['name'] ?? null], $s['id'] ?? null);
        }

        if ($row['down_mbps'] === null) {
            return ['status' => 'failed', 'note' => 'The speedtest command returned no download figure.'] + $row;
        }

        $increases = [];
        foreach (['loaded_down_ms', 'loaded_up_ms'] as $k) {
            if ($row[$k] !== null && $row['idle_ms'] !== null) {
                $increases[] = $row[$k] - $row['idle_ms'];
            }
        }
        if ($increases) {
            $row['latency_increase_ms'] = round(max($increases), 1);
            $row['latency_grade'] = SpeedTest::grade($row['latency_increase_ms']);
        }

        return $row;
    }

    private static function label(array $parts, mixed $id): ?string
    {
        $text = implode(', ', array_filter($parts, fn ($p) => is_string($p) && $p !== ''));
        if ($text === '') {
            return null;
        }

        return mb_substr($text.($id ? " (#$id)" : ''), 0, 190);
    }

    /** @param array{kind: string, path: string, version: string}|null $tool */
    private static function failed(string $note, ?array $tool = null): array
    {
        return ['status' => 'failed', 'note' => $note, 'backend' => $tool['kind'] ?? 'speedtest'];
    }

    /** The program reports errors as JSON log lines (Ookla) or plain text; keep the message only. */
    private static function errorText(string $raw): string
    {
        $messages = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            $j = json_decode($line, true);
            $messages[] = is_array($j) ? (string) ($j['message'] ?? $j['error'] ?? $line) : $line;
        }
        $text = trim(implode(' ', array_filter(array_map('trim', $messages))));

        return $text === '' ? 'no output' : mb_substr($text, 0, 300);
    }

    /**
     * Runs a command without a shell, with a hard time limit.
     *
     * @param  list<string>  $cmd
     * @return array{0: int, 1: string, 2: string, 3: bool} exit code, stdout, stderr, timed out
     */
    private static function exec(array $cmd, int $timeoutSec): array
    {
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return [127, '', 'could not start '.$cmd[0], false];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + $timeoutSec;
        $timedOut = false;
        $exit = -1;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(50_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [$exit, $out, $err, $timedOut];
    }
}
