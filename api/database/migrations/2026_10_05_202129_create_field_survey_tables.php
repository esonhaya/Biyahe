<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_survey_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->string('title', 180);
            $table->text('purpose');
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('observer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observer_reference', 150);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('status', 30)->default('PLANNED');
            $table->boolean('is_synthetic')->default(false);
            $table->text('notes')->nullable();
            $table->string('import_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['city_id', 'status', 'is_synthetic']);
            $table->index(['route_pattern_id', 'status']);
        });

        Schema::create('field_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->foreignId('survey_session_id')->constrained('transport_survey_sessions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('observation_type', 30);
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('route_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fare_from_stop_id')->nullable()->constrained('stops')->nullOnDelete();
            $table->foreignId('fare_to_stop_id')->nullable()->constrained('stops')->nullOnDelete();
            $table->timestamp('observed_at');
            $table->string('location_method', 20)->default('UNKNOWN');
            $table->string('location_precision', 30)->default('UNKNOWN');
            $table->decimal('reported_accuracy_m', 8, 2)->nullable();
            $table->json('observed_fact');
            $table->text('notes')->nullable();
            $table->decimal('fare_amount', 8, 2)->nullable();
            $table->char('fare_currency', 3)->nullable();
            $table->string('passenger_class', 40)->nullable();
            $table->unsignedSmallInteger('observation_window_minutes')->nullable();
            $table->json('supporting_references')->nullable();
            $table->string('review_status', 30)->default('SUBMITTED');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('import_hash', 64);
            $table->timestamps();
            $table->unique(['survey_session_id', 'sequence']);
            $table->index(['survey_session_id', 'review_status']);
            $table->index(['observation_type', 'observed_at']);
            $table->index(['route_pattern_id', 'observation_type']);
        });

        Schema::create('survey_trace_points', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->foreignId('survey_session_id')->constrained('transport_survey_sessions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('observed_at')->nullable();
            $table->decimal('reported_accuracy_m', 8, 2)->nullable();
            $table->string('location_method', 20)->default('DEVICE');
            $table->string('location_precision', 30)->default('UNKNOWN');
            $table->string('import_hash', 64);
            $table->timestamps();
            $table->unique(['survey_session_id', 'sequence']);
            $table->index(['survey_session_id', 'sequence']);
        });

        Schema::create('field_evidence_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->string('claim_type', 50);
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->string('target_stable_id', 150);
            $table->string('comparison', 40);
            $table->text('claim_text');
            $table->string('review_status', 20)->default('PROPOSED');
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('attached_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['target_type', 'target_id', 'claim_type', 'review_status'], 'field_claim_target_review_idx');
        });

        Schema::create('field_evidence_claim_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_evidence_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_observation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['field_evidence_claim_id', 'field_observation_id'], 'field_claim_observation_unique');
        });

        Schema::create('field_evidence_claim_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_evidence_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provenance_source_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['field_evidence_claim_id', 'provenance_source_id'], 'field_claim_source_unique');
        });

        foreach (['field_observations', 'survey_trace_points'] as $tableName) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $nullable = $tableName === 'survey_trace_points' ? ' NOT NULL' : ' NULL';
                DB::statement("ALTER TABLE {$tableName} ADD COLUMN location geography(Point,4326){$nullable}");
                DB::statement("CREATE INDEX {$tableName}_location_gix ON {$tableName} USING GIST (location)");
            } else {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->text('location')->nullable();
                });
            }
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE field_observations ADD CONSTRAINT field_observations_sequence_positive CHECK (sequence > 0)');
            DB::statement('ALTER TABLE field_observations ADD CONSTRAINT field_observations_accuracy_valid CHECK (reported_accuracy_m IS NULL OR (reported_accuracy_m >= 0 AND reported_accuracy_m <= 5000))');
            DB::statement("ALTER TABLE field_observations ADD CONSTRAINT field_observations_precision_valid CHECK (location_precision IN ('POINT_ESTIMATE', 'BOARDING_AREA', 'LANDMARK', 'GENERAL_AREA', 'UNKNOWN'))");
            DB::statement('ALTER TABLE survey_trace_points ADD CONSTRAINT survey_trace_points_sequence_positive CHECK (sequence > 0)');
            DB::statement('ALTER TABLE survey_trace_points ADD CONSTRAINT survey_trace_points_accuracy_valid CHECK (reported_accuracy_m IS NULL OR (reported_accuracy_m >= 0 AND reported_accuracy_m <= 5000))');
            DB::statement("ALTER TABLE survey_trace_points ADD CONSTRAINT survey_trace_points_precision_valid CHECK (location_precision IN ('POINT_ESTIMATE', 'BOARDING_AREA', 'LANDMARK', 'GENERAL_AREA', 'UNKNOWN'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_evidence_claim_sources');
        Schema::dropIfExists('field_evidence_claim_observations');
        Schema::dropIfExists('field_evidence_claims');
        Schema::dropIfExists('survey_trace_points');
        Schema::dropIfExists('field_observations');
        Schema::dropIfExists('transport_survey_sessions');
    }
};
