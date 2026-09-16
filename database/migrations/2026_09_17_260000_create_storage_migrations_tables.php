<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_migrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_disk', 100);
            $table->string('target_disk', 100);
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('copied_files')->default(0);
            $table->unsignedInteger('verified_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->timestampTz('cutover_at')->nullable();
            $table->timestampTz('source_cleaned_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('storage_relocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('storage_migration_id')->constrained('storage_migrations')->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained('asset_files')->cascadeOnDelete();
            $table->string('source_disk', 100);
            $table->string('target_disk', 100);
            $table->string('source_key', 1024);
            $table->string('target_key', 1024);
            $table->char('sha256', 64);
            $table->boolean('is_verified')->default(false)->index();
            $table->timestampTz('cutover_completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['storage_migration_id', 'asset_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_relocations');
        Schema::dropIfExists('storage_migrations');
    }
};
