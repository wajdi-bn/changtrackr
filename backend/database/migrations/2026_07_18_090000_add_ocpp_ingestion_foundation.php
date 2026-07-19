<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->string('ocpp_identity', 80)->nullable()->unique()->after('reference');
            $table->string('ocpp_auth_secret_hash')->nullable()->after('ocpp_version');
            $table->string('ocpp_registration_status', 32)->default('unknown')->after('ocpp_auth_secret_hash');
            $table->string('ocpp_status', 40)->nullable()->after('ocpp_registration_status');
            $table->string('ocpp_error_code', 120)->nullable()->after('ocpp_status');
            $table->timestampTz('ocpp_connected_at')->nullable()->after('ocpp_error_code');
            $table->timestampTz('ocpp_disconnected_at')->nullable()->after('ocpp_connected_at');
            $table->timestampTz('ocpp_last_message_at')->nullable()->after('ocpp_disconnected_at');
            $table->timestampTz('ocpp_last_status_at')->nullable()->after('ocpp_last_message_at');
        });

        DB::table('stations')->orderBy('id')->eachById(function (object $station): void {
            DB::table('stations')->where('id', $station->id)->update([
                'ocpp_identity' => $station->reference,
            ]);
        });

        Schema::table('connectors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ocpp_connector_id')->nullable()->after('external_id');
            $table->string('ocpp_status', 40)->nullable()->after('status');
            $table->string('ocpp_error_code', 120)->nullable()->after('ocpp_status');
            $table->timestampTz('ocpp_last_status_at')->nullable()->after('ocpp_error_code');
        });

        DB::table('connectors')
            ->orderBy('station_id')
            ->orderBy('id')
            ->get()
            ->groupBy('station_id')
            ->each(function ($connectors): void {
                foreach ($connectors->values() as $index => $connector) {
                    DB::table('connectors')->where('id', $connector->id)->update([
                        'ocpp_connector_id' => $index + 1,
                    ]);
                }
            });

        Schema::table('connectors', function (Blueprint $table): void {
            $table->unique(['station_id', 'ocpp_connector_id']);
        });

        Schema::create('ocpp_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->uuid('connection_id')->nullable();
            $table->string('message_id', 120);
            $table->string('protocol_version', 16);
            $table->string('action', 64);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('processing_status', 24)->default('received');
            $table->text('processing_error')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestamps();

            $table->unique(['station_id', 'message_id', 'action']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['station_id', 'action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocpp_events');

        Schema::table('connectors', function (Blueprint $table): void {
            $table->dropUnique(['station_id', 'ocpp_connector_id']);
            $table->dropColumn([
                'ocpp_connector_id',
                'ocpp_status',
                'ocpp_error_code',
                'ocpp_last_status_at',
            ]);
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropUnique(['ocpp_identity']);
            $table->dropColumn([
                'ocpp_identity',
                'ocpp_auth_secret_hash',
                'ocpp_registration_status',
                'ocpp_status',
                'ocpp_error_code',
                'ocpp_connected_at',
                'ocpp_disconnected_at',
                'ocpp_last_message_at',
                'ocpp_last_status_at',
            ]);
        });
    }
};
