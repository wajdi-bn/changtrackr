<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intervention_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('diagnosis');
            $table->text('actions_taken');
            $table->string('final_outcome')->index();
            $table->json('safety_checks');
            $table->json('parts')->nullable();
            $table->text('observations')->nullable();
            $table->unsignedInteger('actual_duration_minutes');
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('intervention_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase')->index();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->string('caption', 500)->nullable();
            $table->timestamps();

            $table->index(['intervention_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_photos');
        Schema::dropIfExists('intervention_reports');
    }
};
