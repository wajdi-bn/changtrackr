<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->timestamp('idle_started_at')->nullable()->after('duration_seconds');
            $table->timestamp('idle_last_ocpp_status_at')->nullable()->after('idle_started_at');
            $table->unsignedInteger('idle_seconds')->default(0)->after('idle_last_ocpp_status_at');
            $table->unsignedInteger('idle_grace_seconds')->default(300)->after('idle_seconds');
            $table->unsignedInteger('idle_fee_millimes')->default(0)->after('idle_fee_per_minute_millimes');
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'idle_started_at',
                'idle_last_ocpp_status_at',
                'idle_seconds',
                'idle_grace_seconds',
                'idle_fee_millimes',
            ]);
        });
    }
};
