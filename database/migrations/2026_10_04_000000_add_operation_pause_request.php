<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_runs', function (Blueprint $table): void {
            $table->boolean('pause_requested')->default(false);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: restore a verified backup instead.');
    }
};
