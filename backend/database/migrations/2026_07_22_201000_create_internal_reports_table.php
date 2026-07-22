<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->string('category', 40)->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('related_type', 40)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('sender_archived_at')->nullable();
            $table->timestampTz('recipient_archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['recipient_id', 'status', 'sent_at']);
            $table->index(['sender_id', 'status', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_reports');
    }
};
