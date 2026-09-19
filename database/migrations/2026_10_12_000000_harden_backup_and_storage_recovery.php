<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_records', function (Blueprint $table): void {
            $table->unsignedSmallInteger('manifest_schema_version')->default(1);
            $table->string('backup_format', 64)->default('fotoarchief-local-backup-v1');
            $table->jsonb('manifest')->nullable();
        });

        Schema::table('storage_migrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('required_bytes')->default(0);
            $table->unsignedBigInteger('available_bytes')->nullable();
            $table->jsonb('preflight_report')->nullable();
        });

        Schema::create('storage_tombstones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('storage_migration_id')->constrained('storage_migrations')->cascadeOnDelete();
            $table->foreignUlid('storage_relocation_id')->constrained('storage_relocations')->cascadeOnDelete();
            $table->string('disk', 100);
            $table->string('object_key', 1024);
            $table->char('sha256', 64);
            $table->string('status', 32)->default('pending')->index();
            $table->timestampTz('retained_until');
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['storage_migration_id', 'object_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery evidence and tombstones require a forward-only migration.');
    }
};
