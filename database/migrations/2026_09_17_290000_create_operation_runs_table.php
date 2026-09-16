<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('operation_type', 60)->index();
            $table->string('status', 30)->default('queued')->index();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->jsonb('result')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->string('claim_token', 40)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });

        // Per-file relocation failures must be readable by the operator rather than
        // collapsing into a single run-level error.
        Schema::table('storage_relocations', function (Blueprint $table): void {
            $table->text('error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storage_relocations', function (Blueprint $table): void {
            $table->dropColumn('error_message');
        });
        Schema::dropIfExists('operation_runs');
    }
};
