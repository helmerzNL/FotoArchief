<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->index(['catalogue_status', 'id'], 'assets_status_keyset_index');
            $table->index(['date_precision', 'date_earliest', 'date_latest'], 'assets_precision_dates_index');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex('assets_status_keyset_index');
            $table->dropIndex('assets_precision_dates_index');
        });
    }
};
