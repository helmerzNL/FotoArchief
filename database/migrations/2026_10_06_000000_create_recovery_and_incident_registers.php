<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->jsonb('report');
            $table->timestampTz('created_at');
        });
        Schema::create('backup_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('version', 32);
            $table->text('location');
            $table->string('manifest_sha256', 64)->unique();
            $table->unsignedBigInteger('byte_size');
            $table->timestampTz('checksum_verified_at');
            $table->timestampsTz();
        });
        Schema::create('restore_drills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('backup_record_id')->constrained();
            $table->string('target_database');
            $table->text('target_directory');
            $table->string('status');
            $table->jsonb('report')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('operational_alert_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('operational_alert_locks')->insert(['id' => 1]);
        Schema::create('operational_incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('incident_key')->unique();
            $table->string('severity');
            $table->string('status');
            $table->string('title');
            $table->text('detail');
            $table->unsignedBigInteger('observations')->default(1);
            $table->timestampTz('opened_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUlid('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->ulid('notification_id');
            $table->timestampTz('notified_at')->nullable();
            $table->string('delivery_error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery evidence and incident history require a forward-only migration.');
    }
};
