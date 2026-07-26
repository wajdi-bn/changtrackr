<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('onboarding_version')->default(0);
            $table->json('onboarding_progress')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('onboarding_dismissed_at')->nullable();
        });

        DB::table('users')
            ->whereNotNull('last_login_at')
            ->update([
                'onboarding_version' => 1,
                'onboarding_completed_at' => DB::raw('last_login_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'onboarding_version',
                'onboarding_progress',
                'onboarding_completed_at',
                'onboarding_dismissed_at',
            ]);
        });
    }
};
