<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('full_name', 120);
            $table->string('email');
            $table->string('company_name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('topic', 40);
            $table->unsignedInteger('estimated_stations')->nullable();
            $table->text('message');
            $table->string('status', 40)->default('new');
            $table->timestamp('scheduled_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('consent_at');
            $table->string('submitted_ip_hash', 64)->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
