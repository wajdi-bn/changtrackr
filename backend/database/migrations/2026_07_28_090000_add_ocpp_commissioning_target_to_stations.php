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
            $table->string('ocpp_commissioning_target', 24)
                ->default('inventory')
                ->after('ocpp_version');
        });

        DB::table('stations')
            ->whereNotNull('ocpp_auth_secret_hash')
            ->update(['ocpp_commissioning_target' => 'external']);
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn('ocpp_commissioning_target');
        });
    }
};
