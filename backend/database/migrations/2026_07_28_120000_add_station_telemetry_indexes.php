<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->index(['station_id', 'started_at'], 'sessions_station_started_at_index');
        });

        Schema::table('ocpp_meter_samples', function (Blueprint $table): void {
            $table->index(
                ['station_id', 'measurand', 'sampled_at'],
                'meter_samples_station_measurand_time_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ocpp_meter_samples', function (Blueprint $table): void {
            $table->dropIndex('meter_samples_station_measurand_time_index');
        });

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropIndex('sessions_station_started_at_index');
        });
    }
};
