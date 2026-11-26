<?php

return [
    // Where evidence sessions (raw chain, exported packages) are kept. Durable storage.
    'storage_dir' => env('MONITOR_STORAGE_DIR', storage_path('app/monitor')),

    // Live status file, rewritten every sample. On a Raspberry Pi point this at
    // tmpfs (e.g. /run/pinetmonitor) so the SD card is not written every second.
    'runtime_dir' => env('MONITOR_RUNTIME_DIR', storage_path('app/monitor/runtime')),

    // Timezone used for session names, schedules, reports and letters (storage is UTC).
    'display_timezone' => env('MONITOR_TIMEZONE', 'Europe/Belgrade'),

    // Optional HTTP Basic password for the dashboard and API (user: "monitor").
    'password' => env('MONITOR_PASSWORD'),

    // Defaults for everything editable on the Settings page.
    'defaults' => [
        'interface' => null,                 // null = follow the default route
        'icmp_targets' => ['1.1.1.1', '8.8.8.8', '9.9.9.9'],   // three independent networks
        'tcp_targets' => ['1.1.1.1:443', '8.8.8.8:443', '9.9.9.9:443'],
        'dns_public' => ['1.1.1.1', '9.9.9.9'],
        'interval_stable_ms' => 1000,
        'interval_suspect_ms' => 500,
        'interval_burst_ms' => 250,
        'interval_incident_ms' => 250,
        'interval_recovery_ms' => 500,
        'probe_timeout_ms' => 800,
        'probe_timeout_fast_ms' => 400,
        'deep_interval_s' => 30,
        'high_latency_ms' => 250,
        'high_jitter_ms' => 80,
        'gap_threshold_s' => 10,
        'contracted_down_mbps' => null,
        'contracted_up_mbps' => null,
        'speed_upload' => true,
        'speed_schedule' => [],              // daily times, e.g. ["03:00", "20:30"]
        'speed_duration_s' => 10,
        'speed_backend' => 'cloudflare',     // cloudflare (built in) | speedtest (installed speedtest command)
        'speedtest_accept_license' => false, // Ookla's CLI only runs once its licence is accepted
        'speedtest_server_id' => null,       // pin a server; null = let the program choose
        'locale' => 'en',
    ],
];
