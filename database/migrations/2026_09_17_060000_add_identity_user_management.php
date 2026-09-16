<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('deactivated_at')->nullable()->index();
            $table->timestampTz('session_revoked_at')->nullable();
        });

        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('email')->index();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('role_ids');
            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'deactivated_at', 'session_revoked_at']);
        });
    }
};
