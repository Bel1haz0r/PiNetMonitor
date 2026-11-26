<?php

namespace App\Monitor\Probes;

use App\Monitor\ProbeGroup;

/**
 * The slow probes — DNS, TLS handshake, HTTP — run in the background and never
 * sit in the measurement path. start() fires them; poll() is called from every
 * tick and advances them without blocking.
 *
 * DNS is asked on two sides separately: the resolvers DHCP assigned and public
 * resolvers queried directly over UDP. That is the only way to tell "the
 * operator's DNS is down" from "DNS works nowhere".
 */
class DeepProbes
{
    private const HTTP_URL = 'http://connectivitycheck.gstatic.com/generate_204';

    private const TLS_URL = 'https://1.1.1.1/cdn-cgi/trace';

    private $multi = null;

    /** @var array<int, array{kind:string, handle:\CurlHandle}> */
    private array $handles = [];

    /** @var array<string, array{sock:\Socket, id:int, group:string, start:int}> */
    private array $dns = [];

    private array $partial = [];

    private int $deadline = 0;

    private bool $running = false;

    /** Completed result set, or null before the first run finished. */
    public ?array $result = null;

    public float $completedMonoMs = 0.0;

    public float $startedMonoMs = 0.0;

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * @param  list<string>  $assignedResolvers
     * @param  list<string>  $publicResolvers
     */
    public function start(array $assignedResolvers, array $publicResolvers, int $timeoutMs = 3000): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $this->startedMonoMs = hrtime(true) / 1e6;
        $this->deadline = hrtime(true) + $timeoutMs * 1_000_000;
        $this->partial = ['http' => [], 'tls' => [], 'dnsAssigned' => [], 'dnsPublic' => [], 'captive' => false];

        $this->multi = curl_multi_init();
        foreach (['http' => self::HTTP_URL, 'tls' => self::TLS_URL] as $kind => $url) {
            $h = curl_init($url);
            curl_setopt_array($h, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_USERAGENT => 'PiNetMonitor',
            ]);
            curl_multi_add_handle($this->multi, $h);
            $this->handles[spl_object_id($h)] = ['kind' => $kind, 'handle' => $h];
            $this->partial[$kind][$url] = null;
        }

        foreach (['dnsAssigned' => $assignedResolvers, 'dnsPublic' => $publicResolvers] as $group => $servers) {
            foreach ($servers as $server) {
                $this->partial[$group][$server] = null;
                $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                if ($sock === false) {
                    continue;
                }
                socket_set_nonblock($sock);
                $id = random_int(1, 0xFFFF);
                $query = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0)."\x07example\x03com\x00".pack('nn', 1, 1);
                @socket_sendto($sock, $query, strlen($query), 0, $server, 53);
                $this->dns["$group|$server"] = ['sock' => $sock, 'id' => $id, 'group' => $group, 'start' => hrtime(true)];
            }
        }
    }

    /** Advances the probes. Returns true on the call that completes a run. */
    public function poll(): bool
    {
        if (! $this->running) {
            return false;
        }

        curl_multi_exec($this->multi, $active);
        while ($info = curl_multi_info_read($this->multi)) {
            $h = $info['handle'];
            $entry = $this->handles[spl_object_id($h)] ?? null;
            if ($entry === null) {
                continue;
            }
            $kind = $entry['kind'];
            $url = $kind === 'http' ? self::HTTP_URL : self::TLS_URL;
            $ok = $info['result'] === CURLE_OK;
            $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $ms = round(curl_getinfo($h, CURLINFO_TOTAL_TIME) * 1000, 1);

            if ($kind === 'http') {
                // generate_204 must answer 204 with no body. Anything else that
                // still "worked" is something answering in its place.
                $this->partial['http'][$url] = ($ok && $code === 204) ? $ms : null;
                $this->partial['captive'] = $ok && $code !== 204 && $code > 0;
            } else {
                $this->partial['tls'][$url] = ($ok && $code > 0) ? $ms : null;
            }
            curl_multi_remove_handle($this->multi, $h);
            unset($this->handles[spl_object_id($h)]);
        }

        foreach ($this->dns as $key => $q) {
            if (@socket_recvfrom($q['sock'], $buf, 512, MSG_DONTWAIT, $from, $port) > 0 && strlen($buf) >= 4) {
                $h = unpack('nid/nflags', $buf);
                // Response bit set, our id, RCODE 0 (NoError).
                if ($h['id'] === $q['id'] && ($h['flags'] & 0x8000) && ($h['flags'] & 0x000F) === 0) {
                    [, $server] = explode('|', $key);
                    $this->partial[$q['group']][$server] = round((hrtime(true) - $q['start']) / 1e6, 1);
                    socket_close($q['sock']);
                    unset($this->dns[$key]);
                }
            }
        }

        $done = ! $this->handles && ! $this->dns;
        if (! $done && hrtime(true) < $this->deadline + 500_000_000) {
            return false;
        }

        foreach ($this->handles as $entry) {
            curl_multi_remove_handle($this->multi, $entry['handle']);
        }
        foreach ($this->dns as $q) {
            socket_close($q['sock']);
        }
        curl_multi_close($this->multi);
        $this->handles = $this->dns = [];
        $this->multi = null;
        $this->running = false;
        $this->result = $this->partial;
        $this->startedMonoMsOfResult = $this->startedMonoMs;
        $this->completedMonoMs = hrtime(true) / 1e6;

        return true;
    }

    /**
     * Results as probe groups. A run only counts as fresh when it STARTED after
     * $notBeforeMonoMs and is younger than $maxAgeMs: a success recorded before
     * the trouble began proves nothing about the trouble.
     *
     * @return array{http:ProbeGroup, tls:ProbeGroup, dnsAssigned:ProbeGroup, dnsPublic:ProbeGroup, captive:bool, fresh:bool}
     */
    public function groups(float $nowMonoMs, float $maxAgeMs, float $notBeforeMonoMs = 0.0): array
    {
        $fresh = $this->result !== null
            && ($nowMonoMs - $this->completedMonoMs) <= $maxAgeMs
            && $this->startedMonoMsOfResult >= $notBeforeMonoMs;
        $r = $this->result ?? [];

        return [
            'http' => new ProbeGroup($r['http'] ?? [], $fresh),
            'tls' => new ProbeGroup($r['tls'] ?? [], $fresh),
            'dnsAssigned' => new ProbeGroup($r['dnsAssigned'] ?? [], $fresh),
            'dnsPublic' => new ProbeGroup($r['dnsPublic'] ?? [], $fresh),
            'captive' => $fresh && ($r['captive'] ?? false),
            'fresh' => $fresh,
        ];
    }

    public float $startedMonoMsOfResult = 0.0;

    public function __destruct()
    {
        if ($this->multi) {
            curl_multi_close($this->multi);
        }
    }
}
