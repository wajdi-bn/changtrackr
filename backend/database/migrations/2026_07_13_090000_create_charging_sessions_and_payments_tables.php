<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('client_name');
            $table->string('station_name');
            $table->string('connector_external_id');
            $table->string('status')->default('charging')->index();
            $table->string('payment_status')->default('unpaid')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->decimal('meter_start_kwh', 14, 3)->default(0);
            $table->decimal('meter_stop_kwh', 14, 3)->nullable();
            $table->decimal('energy_kwh', 10, 3)->default(0);
            $table->unsignedInteger('price_per_kwh_millimes');
            $table->unsignedInteger('session_fee_millimes')->default(0);
            $table->unsignedInteger('total_millimes')->default(0);
            $table->string('currency', 3)->default('TND');
            $table->timestamps();

            $table->index(['organization_id', 'started_at']);
            $table->index(['client_id', 'started_at']);
            $table->index(['connector_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charging_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('provider')->default('simulated');
            $table->string('method');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('amount_millimes');
            $table->string('currency', 3)->default('TND');
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_transaction_id')->nullable()->unique();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('charging_sessions');
    }
};
