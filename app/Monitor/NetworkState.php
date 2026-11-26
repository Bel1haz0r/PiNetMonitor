<?php

namespace App\Monitor;

/**
 * Classification of one sample, ordered loosely by how far the fault sits from
 * the monitoring device: local device, link to router, router, upstream.
 *
 * Mirrors the state model of Internet Evidence Monitor (zoxknez/InternetMonitoring).
 */
enum NetworkState: string
{
    case Ok = 'Ok';
    case AdapterDown = 'AdapterDown';
    case MonitoringGap = 'MonitoringGap';
    case SelfTest = 'SelfTest';
    case WifiRoaming = 'WifiRoaming';
    case GatewayDown = 'GatewayDown';
    case CpeUpstreamUnreachable = 'CpeUpstreamUnreachable';
    case InternetDown = 'InternetDown';
    case DnsIspFailure = 'DnsIspFailure';
    case DnsGlobalFailure = 'DnsGlobalFailure';
    case IcmpFiltered = 'IcmpFiltered';
    case CaptivePortalSuspected = 'CaptivePortalSuspected';
    case PacketLoss = 'PacketLoss';
    case HighLatency = 'HighLatency';
    case HighJitter = 'HighJitter';

    /**
     * States that count as the service being unavailable. Deliberately narrow:
     * DNS failures and degradation are reported separately so the headline
     * downtime figure means one thing only.
     */
    public function isOutage(): bool
    {
        return in_array($this, [
            self::AdapterDown,
            self::GatewayDown,
            self::CpeUpstreamUnreachable,
            self::InternetDown,
        ], true);
    }

    /**
     * The only outage state where the local network was observed working while
     * nothing beyond the router was. Only this carries into a complaint.
     */
    public function isUpstream(): bool
    {
        return $this === self::CpeUpstreamUnreachable;
    }

    /** ok | info | degraded | outage */
    public function severity(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::MonitoringGap, self::SelfTest, self::WifiRoaming => 'info',
            self::IcmpFiltered, self::CaptivePortalSuspected, self::PacketLoss,
            self::HighLatency, self::HighJitter, self::DnsIspFailure,
            self::DnsGlobalFailure => 'degraded',
            default => 'outage',
        };
    }

    /** Ranking used to pick the headline state of an incident. Higher wins. */
    public function rank(): int
    {
        return match ($this) {
            self::AdapterDown => 100,
            self::GatewayDown => 90,
            self::CpeUpstreamUnreachable => 85,
            self::InternetDown => 80,
            self::DnsGlobalFailure => 60,
            self::DnsIspFailure => 55,
            self::PacketLoss => 40,
            self::HighJitter => 35,
            self::HighLatency => 30,
            self::CaptivePortalSuspected => 25,
            self::IcmpFiltered => 20,
            self::WifiRoaming => 10,
            self::SelfTest => 5,
            self::MonitoringGap => 3,
            self::Ok => 0,
        };
    }

    /**
     * How far along the path the fault was isolated to. Never "whose fault it
     * is": a monitor inside the house cannot establish that.
     *
     * none | local_host | local_wifi | local_lan | upstream_path | dns | unknown
     */
    public function domain(bool $wireless = false): string
    {
        return match ($this) {
            self::Ok, self::MonitoringGap, self::SelfTest, self::WifiRoaming, self::IcmpFiltered => 'none',
            self::AdapterDown => 'local_host',
            self::GatewayDown => $wireless ? 'local_wifi' : 'local_lan',
            self::CpeUpstreamUnreachable => 'upstream_path',
            self::DnsIspFailure, self::DnsGlobalFailure => 'dns',
            default => 'unknown',
        };
    }
}
