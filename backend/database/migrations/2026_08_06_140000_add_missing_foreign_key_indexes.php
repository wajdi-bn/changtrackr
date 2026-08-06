<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_events', function (Blueprint $table): void {
            $table->index('organization_id', 'payment_provider_events_organization_id_index');
        });

        Schema::table('availability_transitions', function (Blueprint $table): void {
            $table->index('organization_id', 'availability_transitions_organization_id_index');
        });

        Schema::table('platform_audit_logs', function (Blueprint $table): void {
            $table->index(['actor_id', 'created_at'], 'platform_audit_logs_actor_id_created_at_index');
        });

        Schema::table('charging_attempts', function (Blueprint $table): void {
            $table->index('organization_id', 'charging_attempts_organization_id_index');
        });

        Schema::table('ocpp_commands', function (Blueprint $table): void {
            $table->index('organization_id', 'ocpp_commands_organization_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('ocpp_commands', function (Blueprint $table): void {
            $table->dropIndex('ocpp_commands_organization_id_index');
        });

        Schema::table('charging_attempts', function (Blueprint $table): void {
            $table->dropIndex('charging_attempts_organization_id_index');
        });

        Schema::table('platform_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('platform_audit_logs_actor_id_created_at_index');
        });

        Schema::table('availability_transitions', function (Blueprint $table): void {
            $table->dropIndex('availability_transitions_organization_id_index');
        });

        Schema::table('payment_provider_events', function (Blueprint $table): void {
            $table->dropIndex('payment_provider_events_organization_id_index');
        });
    }
};
