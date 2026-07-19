<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpp_id_tags', function (Blueprint $table): void {
            $table->string('kind', 24)->default('physical')->after('label')->index();
            $table->text('token_ciphertext')->nullable()->after('token_hash');
        });

        Schema::create('charging_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ocpp_id_tag_id')->nullable()->constrained('ocpp_id_tags')->nullOnDelete();
            $table->foreignId('charging_session_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('payment_provider', 40)->default('simulated');
            $table->string('payment_method', 40);
            $table->string('payment_status', 24)->default('pending')->index();
            $table->unsignedInteger('preauthorized_amount_millimes')->default(30000);
            $table->string('currency', 3)->default('TND');
            $table->uuid('payment_idempotency_key')->unique();
            $table->uuid('capture_idempotency_key')->unique();
            $table->string('provider_authorization_id')->nullable()->unique();
            $table->string('simulation_outcome', 24)->default('success');
            $table->decimal('limit_energy_kwh', 10, 3)->nullable();
            $table->unsignedInteger('limit_amount_millimes')->nullable();
            $table->unsignedInteger('limit_duration_minutes')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('command_queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['connector_id', 'status']);
        });

        Schema::create('ocpp_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charging_attempt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('charging_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ocpp_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 48);
            $table->string('status', 24)->default('queued')->index();
            $table->text('encrypted_payload');
            $table->json('result_payload')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->uuid('claimed_by')->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status', 'queued_at']);
            $table->index(['charging_attempt_id', 'action']);
            $table->index(['charging_session_id', 'action']);
        });

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->decimal('current_power_kw', 10, 3)->nullable()->after('energy_kwh');
            $table->decimal('state_of_charge_percent', 5, 2)->nullable()->after('current_power_kw');
            $table->decimal('limit_energy_kwh', 10, 3)->nullable()->after('state_of_charge_percent');
            $table->unsignedInteger('limit_amount_millimes')->nullable()->after('limit_energy_kwh');
            $table->unsignedInteger('limit_duration_minutes')->nullable()->after('limit_amount_millimes');
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'current_power_kw',
                'state_of_charge_percent',
                'limit_energy_kwh',
                'limit_amount_millimes',
                'limit_duration_minutes',
            ]);
        });

        Schema::dropIfExists('ocpp_commands');
        Schema::dropIfExists('charging_attempts');

        Schema::table('ocpp_id_tags', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'token_ciphertext']);
        });
    }
};
