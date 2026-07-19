<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpp_events', function (Blueprint $table): void {
            $table->json('response_payload')->nullable()->after('payload_hash');
        });

        Schema::create('ocpp_id_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('masked_token', 32);
            $table->string('label')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ocpp_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ocpp_id_tag_id')->nullable()->constrained('ocpp_id_tags')->nullOnDelete();
            $table->foreignId('start_event_id')->unique()->constrained('ocpp_events')->cascadeOnDelete();
            $table->foreignId('stop_event_id')->nullable()->unique()->constrained('ocpp_events')->nullOnDelete();
            $table->char('id_tag_hash', 64);
            $table->string('id_tag_masked', 32);
            $table->string('status', 24)->index();
            $table->unsignedBigInteger('meter_start_wh');
            $table->unsignedBigInteger('last_meter_wh')->nullable();
            $table->unsignedBigInteger('meter_stop_wh')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('last_meter_value_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->string('stop_reason', 40)->nullable();
            $table->string('rejection_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status']);
            $table->index(['connector_id', 'status']);
            $table->index(['id_tag_hash', 'status']);
        });

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->foreignId('ocpp_transaction_id')->nullable()->unique()->after('charging_plan_id')
                ->constrained('ocpp_transactions')->nullOnDelete();
            $table->string('source', 24)->default('simulated')->after('reference');
            $table->string('lifecycle_reason', 80)->nullable()->after('status');
            $table->timestampTz('last_meter_value_at')->nullable()->after('meter_stop_kwh');
        });

        Schema::create('ocpp_meter_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ocpp_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ocpp_event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sample_index');
            $table->timestampTz('sampled_at');
            $table->decimal('value', 18, 6);
            $table->string('measurand', 80);
            $table->string('context', 40)->nullable();
            $table->string('phase', 24)->nullable();
            $table->string('location', 24)->nullable();
            $table->string('unit', 16);
            $table->timestamps();

            $table->unique(['ocpp_event_id', 'sample_index']);
            $table->index(['ocpp_transaction_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocpp_meter_samples');

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ocpp_transaction_id');
            $table->dropColumn(['source', 'lifecycle_reason', 'last_meter_value_at']);
        });

        Schema::dropIfExists('ocpp_transactions');
        Schema::dropIfExists('ocpp_id_tags');

        Schema::table('ocpp_events', function (Blueprint $table): void {
            $table->dropColumn('response_payload');
        });
    }
};
