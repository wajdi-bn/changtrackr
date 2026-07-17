<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_requests', function (Blueprint $table): void {
            $table->json('objectives')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('decided_at')->nullable();
        });

        $objectiveMap = [
            'platform' => ['availability_monitoring', 'remote_supervision', 'performance_uptime'],
            'operator' => ['remote_supervision', 'maintenance_coordination'],
            'technician' => ['maintenance_coordination', 'availability_monitoring'],
            'client' => ['charging_activity', 'availability_monitoring'],
            'admin' => ['team_access', 'performance_uptime'],
        ];

        DB::table('demo_requests')->orderBy('id')->each(function (object $request) use ($objectiveMap): void {
            $status = match ($request->status) {
                'new' => 'submitted',
                'contacted', 'demo_scheduled', 'qualified', 'approved' => 'under_review',
                default => $request->status,
            };

            DB::table('demo_requests')->where('id', $request->id)->update([
                'objectives' => json_encode($objectiveMap[$request->topic] ?? ['availability_monitoring']),
                'status' => $status,
                'review_started_at' => $status === 'under_review' ? ($request->updated_at ?? now()) : null,
                'decided_at' => in_array($status, ['provisioned', 'rejected'], true)
                    ? ($request->provisioned_at ?? $request->updated_at ?? now())
                    : null,
            ]);
        });

        Schema::table('demo_requests', function (Blueprint $table): void {
            $table->dropColumn(['topic', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('demo_requests', function (Blueprint $table): void {
            $table->string('topic', 40)->default('platform');
            $table->timestamp('scheduled_at')->nullable();
        });

        DB::table('demo_requests')->where('status', 'submitted')->update(['status' => 'new']);

        Schema::table('demo_requests', function (Blueprint $table): void {
            $table->dropColumn(['objectives', 'rejection_reason', 'review_started_at', 'decided_at']);
        });
    }
};
