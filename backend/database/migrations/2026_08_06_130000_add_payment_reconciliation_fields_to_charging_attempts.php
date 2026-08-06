<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_attempts', function (Blueprint $table): void {
            $table->uuid('release_idempotency_key')->nullable()->unique()->after('capture_idempotency_key');
            $table->string('reconciliation_action', 24)->nullable()->after('expires_at');
            $table->string('reconciliation_status', 24)->nullable()->after('reconciliation_action');
            $table->string('reconciliation_reason', 120)->nullable()->after('reconciliation_status');
            $table->timestampTz('reconciliation_started_at')->nullable()->after('reconciliation_reason');
            $table->timestampTz('reconciled_at')->nullable()->after('reconciliation_started_at');

            $table->index(
                ['reconciliation_action', 'reconciliation_status'],
                'charging_attempts_reconciliation_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('charging_attempts', function (Blueprint $table): void {
            $table->dropIndex('charging_attempts_reconciliation_index');
            $table->dropUnique(['release_idempotency_key']);
            $table->dropColumn([
                'release_idempotency_key',
                'reconciliation_action',
                'reconciliation_status',
                'reconciliation_reason',
                'reconciliation_started_at',
                'reconciled_at',
            ]);
        });
    }
};
