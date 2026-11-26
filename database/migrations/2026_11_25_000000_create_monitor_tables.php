<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
        });

        Schema::create('monitor_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('status')->default('open');           // open | closed
            $t->dateTime('started_at', 3);
            $t->dateTime('ended_at', 3)->nullable();
            $t->dateTime('last_sample_at', 3)->nullable();
            $t->unsignedBigInteger('monitored_ms')->default(0);
            $t->unsignedBigInteger('samples')->default(0);
            $t->unsignedInteger('runs')->default(0);
            $t->string('interface')->nullable();
            $t->string('gateway')->nullable();
            $t->boolean('wireless')->default(false);
            $t->string('chain_path');
            $t->string('last_hash', 64)->nullable();
            $t->unsignedBigInteger('chain_entries')->default(0);
            $t->timestamps();
        });

        Schema::create('minute_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_session_id')->constrained()->cascadeOnDelete();
            $t->dateTime('minute_at');
            $t->unsignedInteger('samples');
            $t->unsignedInteger('ok_samples');
            $t->unsignedInteger('outage_samples');
            $t->float('rtt_min')->nullable();
            $t->float('rtt_avg')->nullable();
            $t->float('rtt_max')->nullable();
            $t->float('gateway_rtt_avg')->nullable();
            $t->float('jitter_avg')->nullable();
            $t->string('worst_state');
            $t->unique(['monitor_session_id', 'minute_at']);
        });

        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_session_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->uuid('correlation_id');
            $t->string('worst_state');
            $t->json('states_seen');
            $t->string('domain');
            $t->dateTime('started_at', 3);
            $t->dateTime('ended_at', 3)->nullable();
            $t->unsignedBigInteger('duration_min_ms')->default(0);
            $t->unsignedBigInteger('duration_max_ms')->default(0);
            $t->unsignedBigInteger('duration_reported_ms')->default(0);
            $t->unsignedInteger('sample_count')->default(0);
            $t->text('detail')->nullable();
            $t->boolean('ended_by_gap')->default(false);
            $t->boolean('started_after_gap')->default(false);
            $t->boolean('is_open')->default(false);
            $t->unsignedTinyInteger('confidence_support')->nullable();
            $t->unsignedTinyInteger('confidence_coverage')->nullable();
            $t->string('confidence_band')->nullable();
            $t->json('evidence')->nullable();
            $t->unsignedBigInteger('peak_local_bps')->nullable();
            $t->index(['monitor_session_id', 'started_at']);
        });

        Schema::create('gaps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_session_id')->constrained()->cascadeOnDelete();
            $t->dateTime('started_at', 3);
            $t->dateTime('ended_at', 3);
            $t->unsignedBigInteger('duration_ms');
            $t->string('cause');                              // Suspended | MonitorNotRunning | SelfTest
        });

        Schema::create('clock_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_session_id')->constrained()->cascadeOnDelete();
            $t->dateTime('observed_at', 3);
            $t->float('jump_seconds');
        });

        Schema::create('speed_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_session_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('measured_at');
            $t->string('status');                             // ok | failed
            $t->string('trigger')->default('manual');        // manual | scheduled
            $t->float('down_mbps')->nullable();
            $t->float('up_mbps')->nullable();
            $t->float('idle_ms')->nullable();
            $t->float('loaded_down_ms')->nullable();
            $t->float('loaded_up_ms')->nullable();
            $t->float('latency_increase_ms')->nullable();
            $t->string('latency_grade')->nullable();          // slight | noticeable | severe
            $t->float('contracted_down_mbps')->nullable();
            $t->float('contracted_up_mbps')->nullable();
            $t->unsignedBigInteger('bytes_used')->default(0);
            $t->boolean('wireless')->default(false);
            $t->text('note')->nullable();
        });

        Schema::create('monitor_commands', function (Blueprint $t) {
            $t->id();
            $t->string('type');                               // new_session | speed_test | reload
            $t->json('payload')->nullable();
            $t->string('status')->default('pending');        // pending | done | failed
            $t->text('result')->nullable();
            $t->timestamps();
        });

        Schema::create('complaint_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_session_id')->nullable()->constrained()->nullOnDelete();
            $t->string('operator_name')->nullable();
            $t->string('subscriber_name')->nullable();
            $t->string('contract_number')->nullable();
            $t->string('service_address')->nullable();
            $t->string('contact_phone')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('customer_type')->default('consumer'); // consumer | non_consumer
            $t->date('event_date')->nullable();
            $t->date('submitted_at')->nullable();
            $t->date('responded_at')->nullable();
            $t->string('outcome')->nullable();                // accepted | rejected
            $t->date('regulator_filed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('case_journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('complaint_case_id')->constrained()->cascadeOnDelete();
            $t->date('entry_date');
            $t->string('kind')->default('note');
            $t->text('text');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['case_journal_entries', 'complaint_cases', 'monitor_commands', 'speed_tests', 'clock_events',
            'gaps', 'incidents', 'minute_stats', 'monitor_sessions', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
