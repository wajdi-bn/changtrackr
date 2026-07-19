<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->string('availability_override', 30)->nullable()->after('status');
            $table->string('availability_reason', 80)->nullable()->after('availability_override');
            $table->string('availability_source', 40)->nullable()->after('availability_reason');
            $table->timestamp('availability_calculated_at')->nullable()->after('availability_source');
            $table->timestamp('availability_monitoring_started_at')->nullable()->after('availability_calculated_at');
        });

        Schema::table('connectors', function (Blueprint $table): void {
            $table->string('availability_reason', 80)->nullable()->after('status');
            $table->string('availability_source', 40)->nullable()->after('availability_reason');
            $table->timestamp('availability_calculated_at')->nullable()->after('availability_source');
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->string('deduplication_key')->nullable()->unique()->after('source');
        });

        Schema::create('availability_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ocpp_event_id')->nullable()->constrained('ocpp_events')->nullOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('from_reason', 80)->nullable();
            $table->string('to_reason', 80);
            $table->string('source', 40);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['station_id', 'occurred_at']);
            $table->index(['connector_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_transitions');

        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropUnique(['deduplication_key']);
            $table->dropColumn('deduplication_key');
        });

        Schema::table('connectors', function (Blueprint $table): void {
            $table->dropColumn([
                'availability_reason',
                'availability_source',
                'availability_calculated_at',
            ]);
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn([
                'availability_override',
                'availability_reason',
                'availability_source',
                'availability_calculated_at',
                'availability_monitoring_started_at',
            ]);
        });
    }
};
