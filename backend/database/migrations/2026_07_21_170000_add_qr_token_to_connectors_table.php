<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connectors', function (Blueprint $table): void {
            $table->uuid('qr_token')->nullable()->unique()->after('external_id');
        });

        DB::table('connectors')->orderBy('id')->each(function (object $connector): void {
            DB::table('connectors')->where('id', $connector->id)->update([
                'qr_token' => (string) Str::uuid(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('connectors', function (Blueprint $table): void {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });
    }
};
