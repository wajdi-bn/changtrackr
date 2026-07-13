<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('reference')->unique();
            $table->string('location_name');
            $table->string('city');
            $table->string('address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('status')->default('offline')->index();
            $table->decimal('max_power_kw', 8, 2);
            $table->string('model');
            $table->string('manufacturer');
            $table->string('ocpp_version')->default('OCPP 1.6J');
            $table->string('model_image')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->decimal('uptime_percent', 5, 2)->default(0);
            $table->decimal('energy_today_kwh', 10, 2)->default(0);
            $table->unsignedInteger('sessions_today')->default(0);
            $table->decimal('utilization_percent', 5, 2)->default(0);
            $table->decimal('revenue_today', 10, 3)->default(0);
            $table->unsignedInteger('open_alerts_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'city']);
        });

        Schema::create('connectors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('type');
            $table->string('current_type');
            $table->decimal('max_power_kw', 8, 2);
            $table->string('status')->default('offline')->index();
            $table->string('error_code')->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connectors');
        Schema::dropIfExists('stations');
    }
};
