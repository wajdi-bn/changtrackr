<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email');
            $table->string('role', 40);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 30)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
