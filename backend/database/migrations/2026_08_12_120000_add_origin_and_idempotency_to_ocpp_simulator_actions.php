<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpp_simulator_actions', function (Blueprint $table): void {
            $table->string('origin', 40)->default('simulation_lab')->after('action');
            $table->uuid('idempotency_key')->nullable()->unique()->after('origin');
            $table->index(['origin', 'requested_by_id', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ocpp_simulator_actions', function (Blueprint $table): void {
            $table->dropIndex(['origin', 'requested_by_id', 'queued_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['origin', 'idempotency_key']);
        });
    }
};
