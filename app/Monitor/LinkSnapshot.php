<?php

namespace App\Monitor;

/** State of the monitored network interface at one moment. */
final class LinkSnapshot
{
    public function __construct(
        public readonly ?string $interface,
        public readonly bool $isUp,
        public readonly string $status,
        public readonly ?string $gateway,
        public readonly bool $wireless = false,
        public readonly ?string $ssid = null,
        public readonly ?string $bssid = null,
        public readonly ?int $signalDbm = null,
        /** @var list<string> */
        public readonly array $resolvers = [],
    ) {}

    public function toArray(): array
    {
        return [
            'interface' => $this->interface,
            'up' => $this->isUp,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'wireless' => $this->wireless,
            'ssid' => $this->ssid,
            'bssid' => $this->bssid,
            'signal_dbm' => $this->signalDbm,
            'resolvers' => $this->resolvers,
        ];
    }
}
