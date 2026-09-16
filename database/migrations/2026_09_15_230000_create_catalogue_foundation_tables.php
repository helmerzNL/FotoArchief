<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('rights_statements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->string('sort_name')->nullable()->index();
            $table->date('birth_date_earliest')->nullable();
            $table->date('birth_date_latest')->nullable();
            $table->string('birth_date_precision', 20)->default('unknown');
            $table->date('death_date_earliest')->nullable();
            $table->date('death_date_latest')->nullable();
            $table->string('death_date_precision', 20)->default('unknown');
            $table->text('biographical_note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('person_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('person_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('alias_type', 50)->default('alternate');
            $table->timestampsTz();
            $table->unique(['person_id', 'normalized_name']);
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->ulid('id');
            // PostgreSQL needs the primary key before the self-referencing foreign key.
            $table->primary('id');
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('location_type', 50)->default('place');
            $table->foreignUlid('parent_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unique(['parent_id', 'normalized_name']);
        });

        Schema::create('location_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('alias_type', 50)->default('alternate');
            $table->timestampsTz();
            $table->unique(['location_id', 'normalized_name']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('collections', function (Blueprint $table): void {
            $table->ulid('id');
            $table->primary('id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('collection_type', 20)->default('collection')->index();
            $table->text('description')->nullable();
            $table->foreignUlid('parent_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('source_type', 50)->default('other');
            $table->string('reference_code', 255)->nullable()->index();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('contributors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('contributor_type', 50)->default('individual');
            $table->string('email', 320)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('accession_number', 100)->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->date('date_earliest')->nullable()->index();
            $table->date('date_latest')->nullable()->index();
            $table->string('date_precision', 20)->default('unknown')->index();
            $table->string('date_display', 255)->nullable();
            $table->text('date_note')->nullable();
            $table->string('catalogue_status', 30)->default('draft')->index();
            $table->timestampsTz();
            $table->index(['date_earliest', 'date_latest'], 'assets_date_range_index');
        });

        Schema::create('asset_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->string('storage_key', 1024)->unique();
            $table->char('sha256', 64)->unique();
            $table->string('media_type', 255);
            $table->unsignedBigInteger('byte_size');
            $table->string('original_filename', 1024)->nullable();
            $table->timestampsTz();
            $table->index(['asset_id', 'created_at']);
        });

        Schema::create('asset_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('change_type', 50)->default('metadata');
            $table->text('change_note')->nullable();
            $table->timestampsTz();
            $table->unique(['asset_id', 'version_number']);
            $table->unique(['asset_id', 'asset_file_id']);
        });

        Schema::create('asset_people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('person_id')->constrained()->restrictOnDelete();
            $table->string('relationship_type', 50)->index();
            $table->decimal('confidence', 5, 4)->nullable()->index();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['asset_id', 'person_id', 'relationship_type']);
        });

        Schema::create('asset_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->string('relationship_type', 50)->index();
            $table->decimal('confidence', 5, 4)->nullable()->index();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['asset_id', 'location_id', 'relationship_type']);
        });

        Schema::create('asset_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['asset_id', 'tag_id']);
        });

        Schema::create('collection_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['collection_id', 'asset_id']);
            $table->unique(['collection_id', 'position']);
        });

        Schema::create('asset_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('source_id')->constrained()->restrictOnDelete();
            $table->string('relationship_type', 50)->default('provenance')->index();
            $table->decimal('confidence', 5, 4)->nullable()->index();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['asset_id', 'source_id', 'relationship_type']);
        });

        Schema::create('asset_contributors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contributor_id')->constrained()->restrictOnDelete();
            $table->string('relationship_type', 50)->default('contributor')->index();
            $table->decimal('confidence', 5, 4)->nullable()->index();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['asset_id', 'contributor_id', 'relationship_type']);
        });

        Schema::create('asset_rights', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('license_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('rights_statement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rights_holder')->nullable()->index();
            $table->date('valid_from')->nullable()->index();
            $table->date('valid_until')->nullable()->index();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index(['asset_id', 'valid_from', 'valid_until'], 'asset_rights_validity_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_date_precision_check CHECK (date_precision IN ('exact', 'circa', 'range', 'before', 'after', 'decade', 'unknown'))");
            DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_date_range_check CHECK (date_earliest IS NULL OR date_latest IS NULL OR date_earliest <= date_latest)');
            DB::statement("ALTER TABLE asset_files ADD CONSTRAINT asset_files_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_asset_file_identifier_changes() RETURNS trigger AS $function$
                BEGIN
                    IF OLD.storage_key IS DISTINCT FROM NEW.storage_key
                        OR OLD.sha256 IS DISTINCT FROM NEW.sha256 THEN
                        RAISE EXCEPTION 'asset file storage keys and checksums are immutable';
                    END IF;
                    RETURN NEW;
                END;
                $function$ LANGUAGE plpgsql;
                CREATE TRIGGER asset_files_immutable_identifiers
                BEFORE UPDATE ON asset_files
                FOR EACH ROW EXECUTE FUNCTION prevent_asset_file_identifier_changes();
                SQL);
            DB::statement("ALTER TABLE people ADD CONSTRAINT people_birth_date_precision_check CHECK (birth_date_precision IN ('exact', 'circa', 'range', 'before', 'after', 'decade', 'unknown'))");
            DB::statement("ALTER TABLE people ADD CONSTRAINT people_death_date_precision_check CHECK (death_date_precision IN ('exact', 'circa', 'range', 'before', 'after', 'decade', 'unknown'))");
            DB::statement('ALTER TABLE people ADD CONSTRAINT people_birth_date_range_check CHECK (birth_date_earliest IS NULL OR birth_date_latest IS NULL OR birth_date_earliest <= birth_date_latest)');
            DB::statement('ALTER TABLE people ADD CONSTRAINT people_death_date_range_check CHECK (death_date_earliest IS NULL OR death_date_latest IS NULL OR death_date_earliest <= death_date_latest)');
            DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_type_check CHECK (collection_type IN ('collection', 'album'))");

            foreach (['asset_people', 'asset_locations', 'asset_sources', 'asset_contributors'] as $table) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_confidence_check CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_verification_status_check CHECK (verification_status IN ('unverified', 'proposed', 'verified', 'disputed'))");
            }
        }
    }

    public function down(): void
    {
        // Catalogue history is forward-only; corrective migrations preserve archival records.
    }
};
