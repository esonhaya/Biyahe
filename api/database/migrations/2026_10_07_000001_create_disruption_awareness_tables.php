<?php

use App\Enums\CommuterReportCategory;
use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('disruptions', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 50);
            $table->string('headline', 180);
            $table->text('description');
            $table->string('severity', 20)->default(DisruptionSeverity::INFO->value);
            $table->string('status', 20)->default(DisruptionStatus::DRAFT->value);
            $table->string('source_class', 40);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('geometry_type', 30)->nullable();
            $table->text('review_notes')->nullable();
            $table->boolean('is_synthetic')->default(false);
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['category', 'status']);
            $table->index(['severity', 'status']);
            $table->index(['source_class', 'status']);
        });

        Schema::create('disruption_routes', function (Blueprint $table): void {
            $table->foreignId('disruption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->primary(['disruption_id', 'route_id']);
            $table->index('route_id');
        });

        Schema::create('disruption_route_patterns', function (Blueprint $table): void {
            $table->foreignId('disruption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_pattern_id')->constrained()->cascadeOnDelete();
            $table->primary(['disruption_id', 'route_pattern_id']);
            $table->index('route_pattern_id');
        });

        Schema::create('disruption_stops', function (Blueprint $table): void {
            $table->foreignId('disruption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained()->cascadeOnDelete();
            $table->primary(['disruption_id', 'stop_id']);
            $table->index('stop_id');
        });

        Schema::create('disruption_places', function (Blueprint $table): void {
            $table->foreignId('disruption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->primary(['disruption_id', 'place_id']);
            $table->index('place_id');
        });

        Schema::create('disruption_transfers', function (Blueprint $table): void {
            $table->foreignId('disruption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_relationship_id')->constrained()->cascadeOnDelete();
            $table->primary(['disruption_id', 'transfer_relationship_id']);
            $table->index('transfer_relationship_id');
        });

        Schema::create('commuter_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->string('category', 50);
            $table->string('status', 30)->default(CommuterReportStatus::SUBMITTED->value);
            $table->text('description');
            $table->timestamp('observed_at');
            $table->timestamp('submitted_at');
            $table->text('source_reference')->nullable();
            $table->decimal('location_accuracy_m', 8, 2)->nullable();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_relationship_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('commuter_reports')->nullOnDelete();
            $table->foreignId('disruption_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reporter_token_hash', 128)->nullable();
            $table->text('review_notes')->nullable();
            $table->boolean('is_synthetic')->default(false);
            $table->timestamps();
            $table->index(['status', 'observed_at']);
            $table->index(['category', 'observed_at']);
            $table->index(['route_id', 'status']);
            $table->index(['stop_id', 'status']);
            $table->index(['place_id', 'status']);
            $table->index(['transfer_relationship_id', 'status']);
        });

        Schema::create('commuter_report_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commuter_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['commuter_report_id', 'created_at']);
        });

        Schema::create('disruption_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disruption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['disruption_id', 'created_at']);
        });

        $this->addSpatialColumn('disruptions', 'geometry', 'geometry(Geometry,4326)');
        $this->addSpatialColumn('commuter_reports', 'location', 'geography(Point,4326)');

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX disruptions_geometry_gix ON disruptions USING GIST (geometry)');
            DB::statement('CREATE INDEX commuter_reports_location_gix ON commuter_reports USING GIST (location)');
            DB::statement("ALTER TABLE disruptions ADD CONSTRAINT disruptions_category_valid CHECK (category IN ('".implode("','", DisruptionCategory::values())."'))");
            DB::statement("ALTER TABLE disruptions ADD CONSTRAINT disruptions_status_valid CHECK (status IN ('".implode("','", DisruptionStatus::values())."'))");
            DB::statement("ALTER TABLE disruptions ADD CONSTRAINT disruptions_severity_valid CHECK (severity IN ('".implode("','", DisruptionSeverity::values())."'))");
            DB::statement("ALTER TABLE disruptions ADD CONSTRAINT disruptions_source_class_valid CHECK (source_class IN ('".implode("','", DisruptionSourceClass::values())."'))");
            DB::statement('ALTER TABLE disruptions ADD CONSTRAINT disruptions_interval_valid CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at)');
            DB::statement("ALTER TABLE commuter_reports ADD CONSTRAINT commuter_reports_category_valid CHECK (category IN ('".implode("','", CommuterReportCategory::values())."'))");
            DB::statement("ALTER TABLE commuter_reports ADD CONSTRAINT commuter_reports_status_valid CHECK (status IN ('".implode("','", CommuterReportStatus::values())."'))");
            DB::statement('ALTER TABLE commuter_reports ADD CONSTRAINT commuter_reports_accuracy_valid CHECK (location_accuracy_m IS NULL OR location_accuracy_m >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commuter_report_reviews');
        Schema::dropIfExists('disruption_reviews');
        Schema::dropIfExists('commuter_reports');
        Schema::dropIfExists('disruption_transfers');
        Schema::dropIfExists('disruption_places');
        Schema::dropIfExists('disruption_stops');
        Schema::dropIfExists('disruption_route_patterns');
        Schema::dropIfExists('disruption_routes');
        Schema::dropIfExists('disruptions');
    }

    private function addSpatialColumn(string $tableName, string $columnName, string $type): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$type} NULL");

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName): void {
            $table->text($columnName)->nullable();
        });
    }
};
