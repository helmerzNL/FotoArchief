<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One installation serves one historical society, so this table holds
        // at most one row (id 1). It is content of the installation, not a brand.
        Schema::create('archive_profile', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('name', 160);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_profile');
    }
};
