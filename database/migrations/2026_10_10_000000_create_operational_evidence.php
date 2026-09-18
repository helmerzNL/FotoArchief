<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incident_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('operational_incidents')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('severity', 20);
            $table->ulid('notification_id')->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->index(['incident_id', 'created_at']);
        });
        Schema::create('operation_notification_reads', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained('operation_runs')->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->timestampTz('read_at');
            $table->primary(['user_id', 'run_id']);
        });
        Schema::create('acceptance_evidence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('version', 32);
            $table->string('environment', 20);
            $table->string('source', 30);
            $table->string('kind', 30);
            $table->string('result', 20);
            $table->string('reference', 500);
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->index();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration.');
    }
};
