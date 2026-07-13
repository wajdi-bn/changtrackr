<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->string('problem_type');
            $table->string('severity')->index();
            $table->string('status')->default('new')->index();
            $table->string('source')->default('system');
            $table->text('description');
            $table->text('ocpp_log')->nullable();
            $table->text('suggested_cause')->nullable();
            $table->text('recommended_action')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['assigned_technician_id', 'status']);
        });

        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->text('description');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('interventions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('status')->default('assigned')->index();
            $table->string('priority')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->text('problem');
            $table->text('diagnosis')->nullable();
            $table->text('resolution')->nullable();
            $table->string('final_status')->nullable();
            $table->text('comments')->nullable();
            $table->json('parts')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['assigned_technician_id', 'status']);
        });

        Schema::create('intervention_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->text('description');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_events');
        Schema::dropIfExists('interventions');
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alerts');
    }
};
