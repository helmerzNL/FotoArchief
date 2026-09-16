<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->string('role', 40)->primary();
            $table->string('state', 40)->default('ok');
            $table->timestampTz('last_seen_at');
            $table->jsonb('details')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
