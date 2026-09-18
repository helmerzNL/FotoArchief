<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            $table->jsonb('approval_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration.');
    }
};
