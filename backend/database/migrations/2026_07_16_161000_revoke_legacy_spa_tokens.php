<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')->delete();
    }

    public function down(): void
    {
        // Revoked credentials cannot be restored safely.
    }
};
