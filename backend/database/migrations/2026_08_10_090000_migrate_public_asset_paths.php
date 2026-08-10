<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const STATION_IMAGES = [
        '/assets/charger-delta-ufc-100.png' => '/assets/stations/models/delta-ufc-100.webp',
        '/assets/charger-enext-park-dc.png' => '/assets/stations/models/enext-park-dc.webp',
        '/assets/charger-evbox-troniq.png' => '/assets/stations/models/evbox-troniq.webp',
        '/assets/charger-powerdot-dc-120.png' => '/assets/stations/models/powerdot-dc-120.webp',
        '/assets/charger-raption-100.png' => '/assets/stations/models/raption-100.webp',
        '/assets/charger-sicharge-d.png' => '/assets/stations/models/sicharge-d.webp',
        '/assets/charger-terra-hp-150.png' => '/assets/stations/models/terra-hp-150.webp',
        '/assets/charger-tritium-rtm50.png' => '/assets/stations/models/tritium-rtm50.webp',
    ];

    public function up(): void
    {
        foreach (self::STATION_IMAGES as $oldPath => $newPath) {
            DB::table('stations')->where('model_image', $oldPath)->update(['model_image' => $newPath]);
        }

        DB::table('users')
            ->where('avatar_url', '/assets/avatar-vendor-1.jpg')
            ->update(['avatar_url' => '/assets/avatars/admin-sami-ben-amor.webp']);
    }

    public function down(): void
    {
        foreach (self::STATION_IMAGES as $oldPath => $newPath) {
            DB::table('stations')->where('model_image', $newPath)->update(['model_image' => $oldPath]);
        }

        DB::table('users')
            ->where('avatar_url', '/assets/avatars/admin-sami-ben-amor.webp')
            ->update(['avatar_url' => '/assets/avatar-vendor-1.jpg']);
    }
};
