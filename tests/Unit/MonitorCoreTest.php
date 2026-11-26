<?php

namespace Tests\Unit;

use App\Monitor\AdaptiveCadence;
use App\Monitor\ConfidenceScorer;
use App\Monitor\EvidenceChain;
use App\Monitor\IncidentDetector;
use App\Monitor\LinkSnapshot;
use App\Monitor\NetworkState;
use App\Monitor\ProbeCycle;
use App\Monitor\ProbeGroup;
use App\Monitor\StateClassifier;
use Tests\TestCase;

class MonitorCoreTest extends TestCase
{
    private function cycle(array $o = []): ProbeCycle
    {
        $ok = ['1.1.1.1' => 10.0, '8.8.8.8' => 12.0, '9.9.9.9' => 11.0];
        $down = array_fill_keys(array_keys($ok), null);

        return new ProbeCycle(
            link: new LinkSnapshot('eth0', $o['up'] ?? true, ($o['up'] ?? true) ? 'up' : 'down', $o['gwAddr'] ?? '192.168.1.1'),
            gateway: array_key_exists('gw', $o) ? ($o['gw'] === 'none' ? ProbeGroup::none() : new ProbeGroup(['192.168.1.1' => $o['gw']])) : new ProbeGroup(['192.168.1.1' => 1.0]),
            externalIcmp: new ProbeGroup($o['icmp'] ?? (($o['ext'] ?? true) ? $ok : $down)),
            externalTcp: new ProbeGroup($o['tcp'] ?? (($o['ext'] ?? true) ? $ok : $down)),
            http: $o['http'] ?? ProbeGroup::none(),
            tls: ProbeGroup::none(),
            dnsAssigned: $o['dnsAssigned'] ?? ProbeGroup::none(),
            dnsPublic: $o['dnsPublic'] ?? ProbeGroup::none(),
            captivePortalSuspected: $o['captive'] ?? false,
            selfTestRunning: $o['self'] ?? false,
        );
    }

    private function state(array $o = [], array $ctx = []): NetworkState
    {
        return (new StateClassifier)->classify($this->cycle($o), $ctx)[0];
    }

    public function test_classifier_walks_from_the_device_outwards(): void
    {
        $this->assertSame(NetworkState::Ok, $this->state());
        $this->assertSame(NetworkState::SelfTest, $this->state(['self' => true, 'ext' => false]));
        // A local fault explains away everything beyond it.
        $this->assertSame(NetworkState::AdapterDown, $this->state(['up' => false, 'ext' => false]));
        $this->assertSame(NetworkState::CpeUpstreamUnreachable, $this->state(['ext' => false]));
        $this->assertSame(NetworkState::GatewayDown, $this->state(['ext' => false, 'gw' => null]));
        // No gateway to test against: the fault cannot be placed.
        $this->assertSame(NetworkState::InternetDown, $this->state(['ext' => false, 'gw' => 'none']));
    }

    public function test_gateway_ignoring_ping_is_not_a_fault_while_traffic_flows(): void
    {
        $this->assertSame(NetworkState::Ok, $this->state(['gw' => null]));
    }

    public function test_filtered_ping_is_never_an_outage(): void
    {
        $none = ['1.1.1.1' => null, '8.8.8.8' => null];
        $state = $this->state(['icmp' => $none]);
        $this->assertSame(NetworkState::IcmpFiltered, $state);
        $this->assertFalse($state->isOutage());
    }

    public function test_stale_success_does_not_prove_the_connection_works(): void
    {
        $staleHttp = new ProbeGroup(['http' => 50.0], fresh: false);
        $this->assertSame(NetworkState::CpeUpstreamUnreachable, $this->state(['ext' => false, 'http' => $staleHttp]));
    }

    public function test_dns_and_degradation_states(): void
    {
        $dead = new ProbeGroup(['192.168.1.1' => null]);
        $alive = new ProbeGroup(['1.1.1.1' => 20.0]);
        $this->assertSame(NetworkState::DnsIspFailure, $this->state(['dnsAssigned' => $dead, 'dnsPublic' => $alive]));
        $this->assertSame(NetworkState::DnsGlobalFailure, $this->state(['dnsAssigned' => $dead, 'dnsPublic' => new ProbeGroup(['1.1.1.1' => null])]));
        $this->assertSame(NetworkState::PacketLoss, $this->state(['icmp' => ['1.1.1.1' => 10.0, '8.8.8.8' => null]]));
        $this->assertSame(NetworkState::HighLatency, $this->state(['icmp' => ['1.1.1.1' => 400.0]]));
        $this->assertSame(NetworkState::HighJitter, $this->state([], ['jitter' => 120.0]));
        $this->assertSame(NetworkState::CaptivePortalSuspected, $this->state(['captive' => true]));
        $this->assertFalse(NetworkState::DnsIspFailure->isOutage());
    }

    public function test_only_router_answering_counts_as_upstream(): void
    {
        $this->assertTrue(NetworkState::CpeUpstreamUnreachable->isUpstream());
        $this->assertFalse(NetworkState::InternetDown->isUpstream());
        $this->assertFalse(NetworkState::GatewayDown->isUpstream());
        $this->assertSame('upstream_path', NetworkState::CpeUpstreamUnreachable->domain());
        $this->assertSame('local_wifi', NetworkState::GatewayDown->domain(wireless: true));
        $this->assertSame('unknown', NetworkState::InternetDown->domain());
    }

    private function at(float $sec): array
    {
        return ['mono' => $sec * 1000, 'wall' => 1_800_000_000 + $sec];
    }

    public function test_incident_durations_carry_honest_bounds(): void
    {
        $d = new IncidentDetector;
        $bad = NetworkState::CpeUpstreamUnreachable;
        $this->assertNull($d->observe($this->at(0), NetworkState::Ok));
        $this->assertNull($d->observe($this->at(1), NetworkState::Ok));
        $this->assertNull($d->observe($this->at(2), $bad, 'first'));
        $this->assertNull($d->observe($this->at(3), $bad));
        $this->assertNull($d->observe($this->at(4), $bad));
        $r = $d->observe($this->at(5), NetworkState::Ok);

        $this->assertSame(2000, $r['duration_min_ms']);       // lastBad - firstBad
        $this->assertSame(4000, $r['duration_max_ms']);       // firstGood - lastGood
        $this->assertSame(3000, $r['duration_reported_ms']);  // midpoint to midpoint
        $this->assertSame(3, $r['sample_count']);
        $this->assertSame(1, $r['number']);
        $this->assertSame('first', $r['detail']);
        $this->assertFalse($r['is_open']);
        $this->assertFalse($d->hasOpenIncident());
    }

    public function test_a_monitoring_pause_cuts_an_outage_instead_of_inflating_it(): void
    {
        $d = new IncidentDetector;
        $bad = NetworkState::GatewayDown;
        $d->observe($this->at(0), NetworkState::Ok);
        $d->observe($this->at(1), $bad);
        $d->observe($this->at(2), $bad);
        // Six hours pass unobserved.
        $cut = $d->observeGap(2000);
        $this->assertTrue($cut['ended_by_gap']);
        $this->assertSame(1000, $cut['duration_min_ms']);
        $this->assertSame(2000, $cut['duration_max_ms']);     // never reaches across the pause

        $d->observe($this->at(21600), $bad);
        $next = $d->observe($this->at(21601), NetworkState::Ok);
        $this->assertTrue($next['started_after_gap']);
        $this->assertSame($cut['correlation_id'], $next['correlation_id']);
        $this->assertSame(1000, $next['duration_max_ms']);    // no stale "last good" from before the pause
        $this->assertSame(2, $next['number']);
    }

    public function test_healthy_sample_after_a_pause_ends_the_event(): void
    {
        $d = new IncidentDetector;
        $d->observe($this->at(0), NetworkState::GatewayDown);
        $first = $d->observeGap(0);
        $d->observe($this->at(100), NetworkState::Ok);
        $d->observe($this->at(101), NetworkState::GatewayDown);
        $second = $d->observe($this->at(102), NetworkState::Ok);
        $this->assertNotSame($first['correlation_id'], $second['correlation_id']);
        $this->assertFalse($second['started_after_gap']);
    }

    public function test_worst_state_is_the_highest_ranked_one_seen(): void
    {
        $d = new IncidentDetector;
        $d->observe($this->at(0), NetworkState::CpeUpstreamUnreachable);
        $d->observe($this->at(1), NetworkState::GatewayDown);
        $r = $d->closeOpenIncident();
        $this->assertSame(NetworkState::GatewayDown, $r['worst_state']);
        $this->assertTrue($r['is_open']);
    }

    public function test_cadence_escalates_and_holds_recovery(): void
    {
        $c = new AdaptiveCadence;
        $this->assertSame(1000, $c->observe(NetworkState::Ok, 0));
        $this->assertSame(500, $c->observe(NetworkState::PacketLoss, 1000));
        $this->assertSame('suspect', $c->phase);
        $this->assertSame(250, $c->observe(NetworkState::PacketLoss, 1500));
        $this->assertSame('burst', $c->phase);
        $this->assertSame(250, $c->observe(NetworkState::GatewayDown, 1750));
        $this->assertSame('incident', $c->phase);
        $this->assertSame(500, $c->observe(NetworkState::Ok, 2000));
        $this->assertSame('recovery', $c->phase);
        $c->observe(NetworkState::Ok, 20000);
        $this->assertSame('recovery', $c->phase);
        $c->observe(NetworkState::Ok, 33000);
        $this->assertSame('stable', $c->phase);
        // Info-level states never drive escalation.
        $c->observe(NetworkState::SelfTest, 34000);
        $this->assertSame('stable', $c->phase);
    }

    public function test_confidence_is_two_dimensional(): void
    {
        $all = ConfidenceScorer::score(NetworkState::CpeUpstreamUnreachable, [
            'link.stayedUp' => true, 'link.wired' => true, 'device.noGap' => true, 'device.noClockJump' => true,
            'device.noSaturation' => true, 'wifi.signalHealthy' => true, 'wifi.noRoaming' => true, 'cpe.gatewayReachable' => true,
            'upstream.icmpFailed' => true, 'upstream.tcpFailed' => true, 'upstream.tlsFailed' => true,
            'upstream.publicDnsFailed' => true, 'upstream.httpFailed' => true,
        ]);
        $this->assertSame(['support' => 100, 'coverage' => 100, 'band' => 'strong'], array_slice($all, 0, 3));

        $thin = ConfidenceScorer::score(NetworkState::CpeUpstreamUnreachable, ['cpe.gatewayReachable' => true]);
        $this->assertSame(100, $thin['support']);
        $this->assertLessThan(40, $thin['coverage']);
        $this->assertSame('insufficient', $thin['band']);

        $nothing = ConfidenceScorer::score(NetworkState::CpeUpstreamUnreachable, []);
        $this->assertSame(0, $nothing['support']);

        // For a gateway fault the gateway falling silent is the finding, not a mark against it.
        $gw = ConfidenceScorer::score(NetworkState::GatewayDown, ['cpe.gatewayReachable' => false, 'link.stayedUp' => true]);
        $this->assertSame(100, $gw['support']);
    }

    public function test_hash_chain_detects_tampering_and_survives_a_torn_tail(): void
    {
        $path = sys_get_temp_dir().'/pinet-chain-'.uniqid().'.jsonl';
        $chain = new EvidenceChain($path);
        $chain->open();
        $chain->append('SessionStart', ['a' => 1]);
        $chain->append('Sample', ['s' => 'Ok', 'note' => 'čćšžđ']);
        $chain->append('Sample', ['s' => 'GatewayDown']);
        $chain->close();

        $ok = EvidenceChain::verify($path);
        $this->assertTrue($ok['valid']);
        $this->assertSame(3, $ok['entries']);
        $this->assertSame($chain->lastHash(), $ok['last_hash']);

        // Editing an earlier line breaks the chain at that line.
        $lines = file($path);
        $tampered = $lines;
        $tampered[1] = str_replace('"Ok"', '"Xx"', $tampered[1]);
        file_put_contents($path, implode('', $tampered));
        $bad = EvidenceChain::verify($path);
        $this->assertFalse($bad['valid']);
        $this->assertSame(2, $bad['first_bad_line']);

        // Removing a line breaks the link of the one after it.
        file_put_contents($path, $lines[0].$lines[2]);
        $this->assertSame(2, EvidenceChain::verify($path)['first_bad_line']);

        // A torn final line (power loss) is cut off and the chain stays appendable.
        file_put_contents($path, implode('', $lines).'{"k":"Sample","n":4,"prev":"abc');
        $this->assertTrue(EvidenceChain::verify($path)['torn_tail']);
        $resumed = new EvidenceChain($path);
        $this->assertTrue($resumed->open()['truncated']);
        $resumed->append('Resume', []);
        $resumed->close();
        $final = EvidenceChain::verify($path);
        $this->assertTrue($final['valid']);
        $this->assertSame(4, $final['entries']);

        // A tampered chain is never appended to.
        file_put_contents($path, implode('', $tampered));
        $this->expectException(\RuntimeException::class);
        (new EvidenceChain($path))->open();
    }
}
