<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
        });

        Schema::table('charging_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
        });

        Schema::dropIfExists('vehicles');
    }

    public function down(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('make', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('license_plate', 32)->nullable();
            $table->decimal('battery_capacity_kwh', 8, 2)->nullable();
            $table->decimal('max_charging_power_kw', 8, 2)->nullable();
            $table->json('connector_types');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });

        Schema::table('charging_attempts', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }
};
