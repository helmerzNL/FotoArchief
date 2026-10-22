<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The relative folder path an original came from. Folder names are often the
        // only context an existing archive has (streets, events), so it is kept as
        // provenance next to the filename. Null for browser uploads.
        Schema::table('quarantine_uploads', function (Blueprint $table): void {
            $table->string('original_path', 1000)->nullable()->index('quarantine_uploads_original_path_index');
        });
        Schema::table('asset_files', function (Blueprint $table): void {
            $table->string('original_path', 1000)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Original paths are provenance; this migration is forward-only.');
    }
};
