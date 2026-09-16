<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quarantine_uploads', function (Blueprint $table): void {
            $table->foreignUlid('duplicate_of_asset_id')->nullable()->after('asset_id')->constrained('assets')->nullOnDelete();
            $table->foreignUlid('duplicate_of_file_id')->nullable()->after('duplicate_of_asset_id')->constrained('asset_files')->nullOnDelete();
            $table->char('detected_sha256', 64)->nullable()->after('duplicate_of_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('quarantine_uploads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_of_asset_id');
            $table->dropConstrainedForeignId('duplicate_of_file_id');
            $table->dropColumn('detected_sha256');
        });
    }
};
