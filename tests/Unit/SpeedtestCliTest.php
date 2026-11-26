<?php

namespace Tests\Unit;

use App\Monitor\Speed\SpeedtestCli;
use PHPUnit\Framework\TestCase;

class SpeedtestCliTest extends TestCase
{
    public function test_ookla_result_is_converted_from_bytes_per_second_and_graded(): void
    {
        $json = '{"type":"log","level":"info","message":"x"}'."\n".json_encode([
            'type' => 'result',
            'ping' => ['latency' => 8.42, 'jitter' => 0.5],
            'download' => ['bandwidth' => 12_500_000, 'bytes' => 150_000_000, 'latency' => ['iqm' => 48.5]],
            'upload' => ['bandwidth' => 2_500_000, 'bytes' => 30_000_000, 'latency' => ['iqm' => 20.1]],
            'server' => ['id' => 1234, 'name' => 'Example ISP', 'location' => 'Belgrade'],
            'result' => ['url' => 'https://www.speedtest.net/result/c/abc'],
        ]);
        $row = SpeedtestCli::parse('ookla', $json);

        $this->assertSame('ok', $row['status']);
        $this->assertSame(100.0, $row['down_mbps']);
        $this->assertSame(20.0, $row['up_mbps']);
        $this->assertSame(8.4, $row['idle_ms']);
        $this->assertSame(48.5, $row['loaded_down_ms']);
        $this->assertSame(40.1, $row['latency_increase_ms']);
        $this->assertSame('noticeable', $row['latency_grade']);
        $this->assertSame(180_000_000, $row['bytes_used']);
        $this->assertSame('Example ISP, Belgrade (#1234)', $row['server']);
        $this->assertSame('https://www.speedtest.net/result/c/abc', $row['result_url']);
    }

    public function test_speedtest_cli_result_is_converted_from_bits_and_has_no_loaded_latency(): void
    {
        $row = SpeedtestCli::parse('sivel', json_encode([
            'download' => 94_300_000.0, 'upload' => 0, 'ping' => 11.03,
            'bytes_received' => 120_000_000, 'bytes_sent' => 0,
            'server' => ['sponsor' => 'Example', 'name' => 'Novi Sad', 'id' => '77'],
        ]));

        $this->assertSame(94.3, $row['down_mbps']);
        $this->assertNull($row['up_mbps']);
        $this->assertSame(11.0, $row['idle_ms']);
        $this->assertNull($row['latency_increase_ms']);
        $this->assertNull($row['latency_grade']);
        $this->assertSame('Example, Novi Sad (#77)', $row['server']);
    }

    public function test_output_without_a_result_is_not_a_measurement(): void
    {
        $this->assertNull(SpeedtestCli::parse('ookla', '{"type":"log","level":"error","message":"Configuration - Could not retrieve or read configuration"}'));
        $this->assertNull(SpeedtestCli::parse('sivel', 'ERROR: Unable to connect to servers'));
        $this->assertSame('failed', SpeedtestCli::parse('ookla', '{"type":"result","download":{"bandwidth":0}}')['status']);
    }
}
