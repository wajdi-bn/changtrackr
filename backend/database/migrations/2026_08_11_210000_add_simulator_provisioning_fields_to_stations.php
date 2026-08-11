<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->string('ocpp_simulator_profile', 80)->nullable()->after('ocpp_commissioning_target');
            $table->string('ocpp_provisioning_status', 24)->default('not_required')->after('ocpp_simulator_profile');
            $table->text('ocpp_provisioning_error')->nullable()->after('ocpp_provisioning_status');
            $table->timestampTz('ocpp_provisioned_at')->nullable()->after('ocpp_provisioning_error');
            $table->index(
                ['ocpp_commissioning_target', 'ocpp_provisioning_status'],
                'stations_commissioning_provisioning_idx',
            );
        });

        DB::table('stations')
            ->where('ocpp_commissioning_target', 'simulator')
            ->whereNotNull('ocpp_auth_secret_hash')
            ->update(['ocpp_provisioning_status' => 'provisioned']);

        DB::table('stations')
            ->where('ocpp_commissioning_target', 'simulator')
            ->whereNull('ocpp_auth_secret_hash')
            ->update(['ocpp_provisioning_status' => 'not_provisioned']);
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropIndex('stations_commissioning_provisioning_idx');
            $table->dropColumn([
                'ocpp_simulator_profile',
                'ocpp_provisioning_status',
                'ocpp_provisioning_error',
                'ocpp_provisioned_at',
            ]);
        });
    }
};
