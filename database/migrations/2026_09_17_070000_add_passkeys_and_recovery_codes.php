<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('credential_id')->unique();
            $table->text('credential_public_key');
            $table->unsignedBigInteger('signature_counter')->nullable();
            $table->json('transports')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('webauthn_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 40)->index();
            $table->string('challenge_hash', 64)->unique();
            $table->text('challenge_ciphertext');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('user_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestampTz('used_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_codes');
        Schema::dropIfExists('webauthn_challenges');
        Schema::dropIfExists('user_passkeys');
    }
};
