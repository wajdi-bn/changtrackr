<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $clientRoleIds = DB::table('roles')->where('name', 'client')->pluck('id');
        $clientIds = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('role_id', $clientRoleIds)
            ->pluck('model_id');

        DB::table('users')->whereIn('id', $clientIds)->update([
            'organization_id' => null,
            'team' => null,
        ]);
    }

    public function down(): void
    {
        // The former organization cannot be reconstructed after clients become global.
    }
};
