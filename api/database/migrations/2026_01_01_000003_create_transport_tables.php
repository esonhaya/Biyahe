<?php

use App\Enums\VerificationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        }

        Schema::create('provenance_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 40);
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->text('url')->nullable();
            $table->date('accessed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('source_type');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->char('country_code', 2)->default('PH');
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'verification_state']);
        });

        Schema::create('vehicle_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('operators', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('places', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('category', 50);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['city_id', 'slug']);
            $table->index(['city_id', 'name']);
            $table->index(['category', 'is_active']);
        });

        Schema::create('place_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['place_id', 'normalized_alias']);
            $table->index(['normalized_alias', 'is_active']);
        });

        Schema::create('stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nearby_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('category', 50)->default('GENERAL');
            $table->boolean('is_boarding_point')->default(true);
            $table->text('boarding_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['city_id', 'is_active']);
            $table->index('nearby_place_id');
        });

        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['city_id', 'code']);
            $table->index(['city_id', 'is_active']);
        });

        Schema::create('route_patterns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('direction', 50);
            $table->text('service_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->unique(['route_id', 'name']);
            $table->index(['route_id', 'is_active']);
        });

        Schema::create('route_pattern_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_pattern_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->boolean('boarding_allowed')->default(true);
            $table->boolean('alighting_allowed')->default(true);
            $table->text('instruction')->nullable();
            $table->json('service_metadata')->nullable();
            $table->timestamps();
            $table->unique(['route_pattern_id', 'sequence']);
            $table->unique(['route_pattern_id', 'stop_id']);
            $table->index(['stop_id', 'route_pattern_id']);
        });

        Schema::create('fares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('origin_stop_id')->nullable()->constrained('stops')->nullOnDelete();
            $table->foreignId('destination_stop_id')->nullable()->constrained('stops')->nullOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fare_class', 40)->default('REGULAR');
            $table->decimal('amount', 8, 2);
            $table->char('currency', 3)->default('PHP');
            $table->decimal('min_distance_km', 8, 2)->nullable();
            $table->decimal('max_distance_km', 8, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['route_id', 'effective_from']);
            $table->index(['verification_state', 'effective_from']);
        });

        $this->addSpatialColumn('places', 'location', 'geography(Point,4326)', false);
        $this->addSpatialColumn('stops', 'location', 'geography(Point,4326)', false);
        $this->addSpatialColumn('route_patterns', 'geometry', 'geometry(MultiLineString,4326)', true);

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX places_location_gix ON places USING GIST (location)');
            DB::statement('CREATE INDEX stops_location_gix ON stops USING GIST (location)');
            DB::statement('ALTER TABLE route_pattern_stops ADD CONSTRAINT route_pattern_stops_sequence_positive CHECK (sequence > 0)');
            DB::statement('ALTER TABLE fares ADD CONSTRAINT fares_effective_dates_valid CHECK (effective_until IS NULL OR effective_until >= effective_from)');
            DB::statement('ALTER TABLE fares ADD CONSTRAINT fares_distance_range_valid CHECK (max_distance_km IS NULL OR min_distance_km IS NULL OR max_distance_km >= min_distance_km)');
            foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'fares'] as $table) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_verification_state_valid CHECK (verification_state IN ('DRAFT', 'VERIFIED', 'STALE', 'RETIRED'))");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fares');
        Schema::dropIfExists('route_pattern_stops');
        Schema::dropIfExists('route_patterns');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('stops');
        Schema::dropIfExists('place_aliases');
        Schema::dropIfExists('places');
        Schema::dropIfExists('operators');
        Schema::dropIfExists('vehicle_types');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provenance_sources');
    }

    private function addSpatialColumn(string $tableName, string $columnName, string $type, bool $nullable): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $nullability = $nullable ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$type} {$nullability}");

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName, $nullable): void {
            $column = $table->text($columnName);
            if ($nullable) {
                $column->nullable();
            }
        });
    }
};
