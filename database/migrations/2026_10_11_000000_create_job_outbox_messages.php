<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 64);
            $table->string('job_class');
            $table->string('queue', 100)->default('ingest');
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(8);
            $table->timestampTz('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_outbox_messages');
    }
};
