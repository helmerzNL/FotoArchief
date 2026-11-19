<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The state of an in-app backup or restore (App-Guidance shared/guidelines/backup-restore.md).
 * It lives in the database because the process answering a poll is not the worker running the
 * job. It is never part of a backup and a restore never touches it, so a restore's own row
 * survives the swap it performs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 10)->index();
            $table->string('status', 10)->index();
            $table->string('phase', 20)->nullable();
            $table->unsignedBigInteger('step')->default(0);
            $table->unsignedBigInteger('steps')->default(0);
            $table->string('error_key', 100)->nullable();
            $table->json('error_params')->nullable();
            $table->json('summary')->nullable();
            $table->json('state')->nullable();
            $table->boolean('includes_files')->default(false);
            $table->string('result_name', 200)->nullable();
            $table->unsignedBigInteger('result_bytes')->nullable();
            $table->decimal('checking_seconds', 10, 2)->nullable();
            $table->decimal('replacing_seconds', 10, 2)->nullable();
            $table->string('requested_by_user_id', 26)->nullable()->index();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
