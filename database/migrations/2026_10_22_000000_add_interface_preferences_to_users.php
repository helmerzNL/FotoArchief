<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Theme, accent colour and sidebar state, so they follow the person across
        // devices. A json document rather than a column per choice: new interface
        // preferences need no migration. Null means "never chosen".
        Schema::table('users', function (Blueprint $table): void {
            $table->json('interface_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('interface_preferences');
        });
    }
};
