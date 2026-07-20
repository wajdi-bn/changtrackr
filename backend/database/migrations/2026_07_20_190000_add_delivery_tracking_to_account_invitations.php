<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_invitations', function (Blueprint $table): void {
            $table->timestamp('last_sent_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('account_invitations', function (Blueprint $table): void {
            $table->dropColumn('last_sent_at');
        });
    }
};
