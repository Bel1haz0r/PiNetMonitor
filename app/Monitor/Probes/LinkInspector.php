<?php

namespace App\Monitor\Probes;

use App\Monitor\LinkSnapshot;

/**
 * Reads the state of the monitored interface: is it up, what is the default
 * gateway, is it wireless, which resolvers did DHCP assign.
 *
 * On Linux (the Raspberry Pi target) the per-tick part is plain sysfs reads;
 * route / Wi-Fi details that need a subprocess are refreshed every few seconds.
 * macOS is supported for development only.
 */
class LinkInspector
{
    private ?string $interface = null;

    private ?string $gateway = null;

    private ?string $ssid = null;

    private ?string $bssid = null;

    private array $resolvers = [];

    private float $refreshedAt = 0.0;

    private string $darwinStatus = 'unknown';

    private readonly bool $linux;

    public function __construct(private readonly ?string $pinnedInterface = null, private readonly float $refreshEverySec = 10.0)
    {
        $this->linux = PHP_OS_FAMILY === 'Linux';
        $this->interface = $pinnedInterface ?: null;
    }

    public function snapshot(bool $forceRefresh = false): LinkSnapshot
    {
        $now = microtime(true);
        if ($forceRefresh || $now - $this->refreshedAt >= $this->refreshEverySec) {
            $this->refresh();
            $this->refreshedAt = $now;
        }

        $status = $this->operState();
        $wireless = $this->interface !== null && $this->linux && is_dir("/sys/class/net/{$this->interface}/wireless");

        return new LinkSnapshot(
            interface: $this->interface,
            isUp: $status === 'up' || ($status === 'unknown' && $this->gateway !== null),
            status: $status,
            gateway: $this->gateway,
            wireless: $wireless,
            ssid: $wireless ? $this->ssid : null,
            bssid: $wireless ? $this->bssid : null,
            signalDbm: $wireless ? $this->signal() : null,
            resolvers: $this->resolvers,
        );
    }

    /** Total rx+tx bytes of the interface, or null when unavailable. */
    public function trafficBytes(): ?int
    {
        if (! $this->linux || $this->interface === null) {
            return null;
        }
        $rx = @file_get_contents("/sys/class/net/{$this->interface}/statistics/rx_bytes");
        $tx = @file_get_contents("/sys/class/net/{$this->interface}/statistics/tx_bytes");

        return ($rx === false || $tx === false) ? null : (int) $rx + (int) $tx;
    }

    private function operState(): string
    {
        if ($this->interface === null) {
            return 'no-interface';
        }
        if ($this->linux) {
            $state = @file_get_contents("/sys/class/net/{$this->interface}/operstate");

            return $state === false ? 'missing' : trim($state);
        }

        return $this->darwinStatus;
    }

    private function refresh(): void
    {
        $this->resolvers = $this->readResolvers();
        $this->linux ? $this->refreshLinux() : $this->refreshDarwin();
    }

    private function refreshLinux(): void
    {
        $cmd = $this->pinnedInterface
            ? 'ip -4 route show default dev '.escapeshellarg($this->pinnedInterface)
            : 'ip -4 route show default';
        $out = $this->exec($cmd);

        // "default via 192.168.1.1 dev eth0 proto dhcp src 192.168.1.5 metric 100"
        if ($out !== null && preg_match('/default via (\S+)(?: dev (\S+))?/', $out, $m)) {
            $this->gateway = $m[1];
            $this->interface = $this->pinnedInterface ?: ($m[2] ?? $this->interface);
        } else {
            // No default route. Keep the last known interface so its state can
            // still be reported, but do not keep a gateway we can no longer name.
            $this->gateway = null;
        }

        if ($this->interface !== null && is_dir("/sys/class/net/{$this->interface}/wireless")) {
            $previous = $this->bssid;
            $link = $this->exec('iw dev '.escapeshellarg($this->interface).' link') ?? '';
            $this->bssid = preg_match('/Connected to ([0-9a-f:]{17})/i', $link, $m) ? strtolower($m[1]) : null;
            $this->ssid = preg_match('/SSID: (.+)/', $link, $m) ? trim($m[1]) : null;
            $this->previousBssid = $previous;
        }
    }

    private ?string $previousBssid = null;

    /** True once after the access point changed under the same connection. */
    public function consumeBssidChange(): bool
    {
        $changed = $this->previousBssid !== null && $this->bssid !== null && $this->previousBssid !== $this->bssid;
        $this->previousBssid = $this->bssid;

        return $changed;
    }

    private function refreshDarwin(): void
    {
        $out = $this->exec('route -n get default') ?? '';
        $this->gateway = preg_match('/gateway: (\S+)/', $out, $m) ? $m[1] : null;
        if (! $this->pinnedInterface && preg_match('/interface: (\S+)/', $out, $m)) {
            $this->interface = $m[1];
        }
        if ($this->interface !== null) {
            $if = $this->exec('ifconfig '.escapeshellarg($this->interface)) ?? '';
            $this->darwinStatus = match (true) {
                $if === '' => 'missing',
                str_contains($if, 'status: active') => 'up',
                str_contains($if, 'status: inactive') => 'down',
                default => 'unknown',
            };
        }
    }

    private function signal(): ?int
    {
        // /proc/net/wireless: " wlan0: 0000   54.  -56.  -256 ..."
        foreach (@file('/proc/net/wireless') ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote((string) $this->interface, '/').':\s+\S+\s+\S+\s+(-?\d+)/', $line, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** @return list<string> IPv4 resolvers assigned to this host, loopback stubs skipped */
    private function readResolvers(): array
    {
        foreach (['/run/systemd/resolve/resolv.conf', '/etc/resolv.conf'] as $file) {
            $found = [];
            foreach (@file($file) ?: [] as $line) {
                if (preg_match('/^\s*nameserver\s+(\d+\.\d+\.\d+\.\d+)/', $line, $m) && ! str_starts_with($m[1], '127.')) {
                    $found[] = $m[1];
                }
            }
            if ($found) {
                return array_values(array_unique($found));
            }
        }

        return [];
    }

    private function exec(string $cmd): ?string
    {
        $out = @shell_exec($cmd.' 2>/dev/null');

        return is_string($out) && $out !== '' ? $out : null;
    }
}
