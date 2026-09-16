<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->date('acquisition_date')->nullable()->after('reference_code');
            $table->text('custody_history')->nullable()->after('description');
        });

        Schema::table('contributors', function (Blueprint $table): void {
            $table->text('contact_details')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('contributors', function (Blueprint $table): void {
            $table->dropColumn('contact_details');
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn(['acquisition_date', 'custody_history']);
        });
    }
};
