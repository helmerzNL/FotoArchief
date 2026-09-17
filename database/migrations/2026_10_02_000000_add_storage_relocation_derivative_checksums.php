<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_relocations', function (Blueprint $table): void {
            $table->json('verified_derivatives')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storage_relocations', function (Blueprint $table): void {
            $table->dropColumn('verified_derivatives');
        });
    }
};
