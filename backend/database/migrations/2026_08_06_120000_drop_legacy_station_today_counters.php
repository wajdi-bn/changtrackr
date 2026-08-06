<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn(['energy_today_kwh', 'sessions_today', 'revenue_today']);
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->decimal('energy_today_kwh', 10, 2)->default(0);
            $table->unsignedInteger('sessions_today')->default(0);
            $table->decimal('revenue_today', 10, 3)->default(0);
        });
    }
};
