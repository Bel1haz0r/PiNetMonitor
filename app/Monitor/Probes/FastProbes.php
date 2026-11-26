<?php

namespace App\Monitor\Probes;

use Socket;

/**
 * The probes that run in every cycle: ICMP echo and TCP connect, all targets
 * in parallel, so a cycle takes as long as the slowest probe rather than
 * their sum.
 *
 * ICMP uses an unprivileged datagram socket (no root needed on Linux when
 * net.ipv4.ping_group_range allows it, which is the Debian/Raspberry Pi OS
 * default). When that is not available it falls back to the `ping` binary.
 */
class FastProbes
{
    private Socket|false|null $icmp = null;

    private int $seq = 0;

    public function usesRawIcmp(): bool
    {
        return $this->icmpSocket() !== false;
    }

    /**
     * @param  list<string>  $icmpTargets  IPv4 addresses
     * @param  list<string>  $tcpTargets  "ip:port"
     * @return array{icmp: array<string, float|null>, tcp: array<string, float|null>} RTT in ms or null
     */
    public function run(array $icmpTargets, array $tcpTargets, int $timeoutMs): array
    {
        $icmp = array_fill_keys($icmpTargets, null);
        $tcp = array_fill_keys($tcpTargets, null);
        $start = hrtime(true);
        $deadline = $start + $timeoutMs * 1_000_000;

        // --- start TCP connects -------------------------------------------------
        $pending = [];
        foreach ($tcpTargets as $target) {
            [$host, $port] = explode(':', $target) + [1 => 443];
            $s = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($s === false) {
                continue;
            }
            socket_set_nonblock($s);
            @socket_connect($s, $host, (int) $port);
            $pending[$target] = $s;
        }

        // --- send ICMP ----------------------------------------------------------
        $sock = $this->icmpSocket();
        $pings = [];
        $seq = 0;
        if ($sock !== false) {
            $seq = $this->seq = ($this->seq + 1) & 0xFFFF;
            $packet = $this->echoRequest($seq);
            foreach ($icmpTargets as $target) {
                @socket_sendto($sock, $packet, strlen($packet), 0, $target, 0);
            }
        } else {
            $pings = $this->startPingProcesses($icmpTargets, $timeoutMs);
        }

        // --- wait ---------------------------------------------------------------
        $icmpOutstanding = $sock !== false ? count($icmpTargets) : 0;
        while (($pending || $icmpOutstanding > 0) && ($now = hrtime(true)) < $deadline) {
            $read = ($sock !== false && $icmpOutstanding > 0) ? [$sock] : [];
            $write = array_values($pending);
            $except = null;
            if (! $read && ! $write) {
                break;
            }
            $remainingUs = (int) (($deadline - $now) / 1000);
            $n = @socket_select($read, $write, $except, 0, max(1000, $remainingUs));
            if ($n === false) {
                break;
            }
            $elapsed = (hrtime(true) - $start) / 1e6;

            foreach ($write as $ready) {
                $target = array_search($ready, $pending, true);
                if ($target === false) {
                    continue;
                }
                if (socket_get_option($ready, SOL_SOCKET, SO_ERROR) === 0) {
                    $tcp[$target] = round($elapsed, 2);
                }
                socket_close($ready);
                unset($pending[$target]);
            }

            foreach ($read as $ready) {
                while (@socket_recvfrom($ready, $buf, 1500, MSG_DONTWAIT, $from, $port) > 0) {
                    if ($this->isEchoReply($buf, $seq) && array_key_exists($from, $icmp) && $icmp[$from] === null) {
                        $icmp[$from] = round((hrtime(true) - $start) / 1e6, 2);
                        $icmpOutstanding--;
                    }
                }
            }
        }

        foreach ($pending as $s) {
            socket_close($s);
        }

        if ($pings) {
            $icmp = $this->collectPingProcesses($pings, $deadline) + $icmp;
        }

        return ['icmp' => $icmp, 'tcp' => $tcp];
    }

    private function icmpSocket(): Socket|false
    {
        if ($this->icmp === null) {
            $s = @socket_create(AF_INET, SOCK_DGRAM, 1 /* IPPROTO_ICMP */);
            if ($s !== false) {
                socket_set_nonblock($s);
            }
            $this->icmp = $s;
        }

        return $this->icmp;
    }

    private function echoRequest(int $seq): string
    {
        $payload = 'pinetmonitor'.pack('J', hrtime(true));
        $header = pack('CCnnn', 8, 0, 0, getmypid() & 0xFFFF, $seq);
        $sum = $this->checksum($header.$payload);

        return pack('CCnnn', 8, 0, $sum, getmypid() & 0xFFFF, $seq).$payload;
    }

    private function checksum(string $data): int
    {
        if (strlen($data) % 2) {
            $data .= "\0";
        }
        $sum = array_sum(unpack('n*', $data));
        while ($sum >> 16) {
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }

        return ~$sum & 0xFFFF;
    }

    private function isEchoReply(string $buf, int $seq): bool
    {
        // macOS hands back the IP header on datagram ICMP sockets; Linux does not.
        if (strlen($buf) >= 28 && (ord($buf[0]) >> 4) === 4) {
            $buf = substr($buf, (ord($buf[0]) & 0x0F) * 4);
        }
        if (strlen($buf) < 8) {
            return false;
        }
        $h = unpack('Ctype/Ccode/nsum/nid/nseq', $buf);

        return $h['type'] === 0 && $h['seq'] === $seq;
    }

    /** @return array<string, array{proc: resource, pipe: resource}> */
    private function startPingProcesses(array $targets, int $timeoutMs): array
    {
        $secs = max(1, (int) ceil($timeoutMs / 1000));
        $procs = [];
        foreach ($targets as $target) {
            $cmd = PHP_OS_FAMILY === 'Darwin'
                ? ['ping', '-n', '-c', '1', '-t', (string) $secs, $target]
                : ['ping', '-n', '-c', '1', '-W', (string) $secs, $target];
            $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($proc)) {
                stream_set_blocking($pipes[1], false);
                $procs[$target] = ['proc' => $proc, 'pipe' => $pipes[1], 'out' => ''];
            }
        }

        return $procs;
    }

    /** @return array<string, float|null> */
    private function collectPingProcesses(array $procs, int $deadline): array
    {
        $results = [];
        while ($procs) {
            foreach ($procs as $target => &$p) {
                $p['out'] .= (string) stream_get_contents($p['pipe']);
                $status = proc_get_status($p['proc']);
                $expired = hrtime(true) >= $deadline + 200_000_000;
                if (! $status['running'] || $expired) {
                    $p['out'] .= (string) stream_get_contents($p['pipe']);
                    $results[$target] = preg_match('/time[=<]([\d.]+)\s*ms/', $p['out'], $m) ? (float) $m[1] : null;
                    if ($status['running']) {
                        proc_terminate($p['proc']);
                    }
                    fclose($p['pipe']);
                    proc_close($p['proc']);
                    unset($procs[$target]);
                }
            }
            unset($p);
            if ($procs) {
                usleep(5000);
            }
        }

        return $results;
    }
}
