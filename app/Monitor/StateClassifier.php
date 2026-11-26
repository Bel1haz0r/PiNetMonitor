<?php

namespace App\Monitor;

/**
 * Turns one ProbeCycle into a verdict. Pure and deterministic: no clock, no
 * network, no state — history arrives through the $context array.
 *
 * Checks run from the device outwards. The order matters: a fault close to
 * the device explains away everything beyond it.
 */
final class StateClassifier
{
    public function __construct(
        private readonly float $highLatencyMs = 250.0,
        private readonly float $highJitterMs = 80.0,
    ) {}

    /**
     * @param  array{jitter?: float|null, bssidChanged?: bool}  $context
     * @return array{0: NetworkState, 1: string} state and technical detail
     */
    public function classify(ProbeCycle $c, array $context = []): array
    {
        // Our own speed test saturates the link on purpose. Anything measured
        // during it describes the test, not the service.
        if ($c->selfTestRunning) {
            return [NetworkState::SelfTest, 'Speed test in progress; connectivity assessment suspended.'];
        }

        return $this->localLink($c)
            ?? $this->routerAndUpstream($c)
            ?? $this->dns($c)
            ?? $this->degradation($c, $context)
            ?? [NetworkState::Ok, 'All attempted probes succeeded.'];
    }

    private function localLink(ProbeCycle $c): ?array
    {
        if ($c->link->isUp) {
            return null;
        }

        return [
            NetworkState::AdapterDown,
            sprintf("Monitored adapter '%s' reports %s.", $c->link->interface ?? '?', $c->link->status),
        ];
    }

    private function routerAndUpstream(ProbeCycle $c): ?array
    {
        // Traffic is flowing, so neither the router nor the uplink is down. A
        // gateway that ignores ICMP while the internet works is not a fault.
        if ($c->anyExternalReachability()) {
            return null;
        }

        if ($c->gateway->allSucceeded()) {
            // The strongest statement a single device can make: the path to the
            // router is healthy and everything past it is not. Still short of
            // naming the operator — a failed WAN port, CPE firmware, a full NAT
            // table or a dropped PPPoE session all look exactly like this.
            return [
                NetworkState::CpeUpstreamUnreachable,
                sprintf(
                    'Gateway %s responds but no external target is reachable (ICMP %s, TCP %s, HTTP %s).',
                    $c->link->gateway, $c->externalIcmp->summary(), $c->externalTcp->summary(), $c->http->summary()
                ),
            ];
        }

        if ($c->gateway->allFailed()) {
            return [
                NetworkState::GatewayDown,
                sprintf('Adapter is up but gateway %s did not respond and no external target is reachable.', $c->link->gateway),
            ];
        }

        // No gateway known. The internet is unreachable but we cannot say where
        // the path breaks.
        return [NetworkState::InternetDown, 'No external target is reachable and gateway state is unknown.'];
    }

    /** Runs only once IP connectivity is confirmed, so a DNS verdict never hides an outage. */
    private function dns(ProbeCycle $c): ?array
    {
        if ($c->dnsAssigned->allFailed() && $c->dnsPublic->anySuccess()) {
            return [
                NetworkState::DnsIspFailure,
                'Every assigned resolver failed while a public resolver answered over the same path.',
            ];
        }

        $attempted = $c->dnsAssigned->attempted() + $c->dnsPublic->attempted();
        $succeeded = $c->dnsAssigned->succeeded() + $c->dnsPublic->succeeded();
        if ($attempted > 0 && $succeeded === 0) {
            return [NetworkState::DnsGlobalFailure, "All $attempted resolvers failed while IP connectivity still worked."];
        }

        return null;
    }

    private function degradation(ProbeCycle $c, array $context): ?array
    {
        if ($c->captivePortalSuspected) {
            return [
                NetworkState::CaptivePortalSuspected,
                'Connectivity endpoint returned unexpected content, characteristic of an intercepting portal.',
            ];
        }

        // ICMP is widely rate-limited and filtered. Calling this an outage while
        // TCP/TLS sail through would manufacture incidents that do not exist.
        if ($c->externalIcmp->allFailed()
            && ($c->externalTcp->anySuccess() || $c->tls->anySuccess() || $c->http->anySuccess())) {
            return [
                NetworkState::IcmpFiltered,
                sprintf('All %d ICMP targets failed while TCP/TLS/HTTP succeeded.', $c->externalIcmp->attempted()),
            ];
        }

        // Stated as what it is: some independent destinations did not answer.
        // Not a loss percentage — one probe per target.
        if ($c->externalIcmp->someFailed()) {
            return [
                NetworkState::PacketLoss,
                sprintf(
                    '%d of %d independent external targets did not answer ICMP (%s). One probe per target: this counts unreachable destinations, not lost packets.',
                    $c->externalIcmp->failed(), $c->externalIcmp->attempted(), implode(', ', $c->externalIcmp->failedTargets())
                ),
            ];
        }

        $jitter = $context['jitter'] ?? null;
        if ($jitter !== null && $jitter >= $this->highJitterMs) {
            return [
                NetworkState::HighJitter,
                sprintf('Round-trip variation %.1f ms exceeds the %.0f ms threshold.', $jitter, $this->highJitterMs),
            ];
        }

        $rtt = $c->averageExternalRtt();
        if ($rtt !== null && $rtt >= $this->highLatencyMs) {
            return [
                NetworkState::HighLatency,
                sprintf('Average external round trip %.1f ms exceeds the %.0f ms threshold.', $rtt, $this->highLatencyMs),
            ];
        }

        if (! empty($context['bssidChanged'])) {
            return [NetworkState::WifiRoaming, sprintf('Access point changed to BSSID %s under the same SSID.', $c->link->bssid)];
        }

        return null;
    }
}
