<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_technician_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->string('type', 24)->index();
            $table->string('priority', 24)->index();
            $table->string('status', 24)->default('active')->index();
            $table->text('instructions');
            $table->timestampTz('first_scheduled_at');
            $table->unsignedInteger('estimated_duration_minutes');
            $table->string('recurrence_frequency', 24)->default('none');
            $table->unsignedSmallInteger('recurrence_interval')->default(1);
            $table->timestampTz('recurrence_ends_at')->nullable();
            $table->timestampTz('next_occurrence_at')->nullable()->index();
            $table->timestampTz('last_generated_at')->nullable();
            $table->unsignedInteger('last_occurrence_number')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['station_id', 'status']);
        });

        Schema::table('interventions', function (Blueprint $table): void {
            $table->foreignId('maintenance_plan_id')->nullable()->after('alert_id')
                ->constrained()->cascadeOnDelete();
            $table->unsignedInteger('maintenance_occurrence_number')->nullable()->after('maintenance_plan_id');
            $table->unique(['maintenance_plan_id', 'maintenance_occurrence_number'], 'maintenance_occurrence_unique');
            $table->unsignedBigInteger('alert_id')->nullable()->change();
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->foreignId('maintenance_intervention_id')->nullable()->after('availability_override')
                ->constrained('interventions')->nullOnDelete();
            $table->string('status_before_maintenance', 40)->nullable()->after('maintenance_intervention_id');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('maintenance_intervention_id');
            $table->dropColumn('status_before_maintenance');
        });

        DB::table('interventions')->whereNotNull('maintenance_plan_id')->delete();
        Schema::table('interventions', function (Blueprint $table): void {
            $table->dropUnique('maintenance_occurrence_unique');
            $table->dropConstrainedForeignId('maintenance_plan_id');
            $table->dropColumn('maintenance_occurrence_number');
            $table->unsignedBigInteger('alert_id')->nullable(false)->change();
        });

        Schema::dropIfExists('maintenance_plans');
    }
};
