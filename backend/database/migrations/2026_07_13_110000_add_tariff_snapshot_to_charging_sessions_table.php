<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tariff_name')->nullable();
            $table->unsignedInteger('idle_fee_per_minute_millimes')->default(0);
            $table->unsignedInteger('minimum_charge_millimes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tariff_id');
            $table->dropColumn(['tariff_name', 'idle_fee_per_minute_millimes', 'minimum_charge_millimes']);
        });
    }
};
