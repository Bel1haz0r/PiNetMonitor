<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speed_tests', function (Blueprint $t) {
            $t->string('backend')->default('cloudflare');   // cloudflare | ookla | sivel | speedtest (none found)
            $t->string('server')->nullable();
            $t->string('result_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('speed_tests', function (Blueprint $t) {
            $t->dropColumn(['backend', 'server', 'result_url']);
        });
    }
};
