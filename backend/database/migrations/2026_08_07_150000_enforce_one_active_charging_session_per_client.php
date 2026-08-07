<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX_NAME = 'charging_sessions_one_active_per_client_unique';

    public function up(): void
    {
        $hasConflicts = DB::table('charging_sessions')
            ->select('client_id')
            ->whereNotNull('client_id')
            ->whereIn('status', ['pending', 'charging', 'stopping'])
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasConflicts) {
            throw new RuntimeException(
                'Cannot enforce one active charging session per client while conflicting sessions exist.',
            );
        }

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON charging_sessions (client_id) WHERE client_id IS NOT NULL AND status IN ('pending', 'charging', 'stopping')",
            self::INDEX_NAME,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
