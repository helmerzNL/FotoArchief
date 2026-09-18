<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_key');
            $table->string('manifest_sha256', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'client_key']);
        });
        Schema::create('upload_session_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('upload_session_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64);
            $table->string('status')->default('receiving');
            $table->string('error_code')->nullable();
            $table->foreignUlid('quarantine_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['upload_session_id', 'sha256']);
        });
        Schema::create('upload_session_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('upload_session_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('byte_size');
            $table->string('sha256', 64);
            $table->unique(['upload_session_item_id', 'position']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Upload receipts require a forward-only migration.');
    }
};
