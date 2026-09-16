<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_files', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(true)->index();
        });

        Schema::table('asset_versions', function (Blueprint $table): void {
            $table->boolean('is_current')->default(true)->index();
        });
    }

    public function down(): void
    {
        Schema::table('asset_versions', function (Blueprint $table): void {
            $table->dropColumn('is_current');
        });

        Schema::table('asset_files', function (Blueprint $table): void {
            $table->dropColumn('is_primary');
        });
    }
};
