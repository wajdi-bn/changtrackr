<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('team')->nullable()->after('avatar_url');
            $table->string('address')->nullable()->after('team');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropColumn(['team', 'address']);
        });
    }
};
