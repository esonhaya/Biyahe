<?php

use App\Enums\BoardingPrecision;
use App\Enums\EvidenceConflictStatus;
use App\Enums\TransferReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provenance_sources', function (Blueprint $table): void {
            $table->date('published_at')->nullable();
            $table->date('observed_at')->nullable();
            $table->json('evidence_scope')->nullable();
            $table->string('conflict_status', 20)->default(EvidenceConflictStatus::NONE->value);
            $table->string('conflict_group', 150)->nullable()->index();
            $table->foreignId('superseded_by_id')->nullable()->constrained('provenance_sources')->nullOnDelete();
            $table->index(['conflict_status', 'checked_at']);
        });

        Schema::table('stops', function (Blueprint $table): void {
            $table->string('boarding_precision', 30)->default(BoardingPrecision::UNKNOWN->value);
            $table->string('boarding_verification_state', 20)->default('DRAFT');
            $table->foreignId('boarding_provenance_source_id')->nullable()->constrained('provenance_sources')->nullOnDelete();
            $table->index(['boarding_precision', 'boarding_verification_state']);
        });

        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->string('service_verification_state', 20)->default('DRAFT');
            $table->string('sequence_verification_state', 20)->default('DRAFT');
            $table->string('boarding_verification_state', 20)->default('DRAFT');
            $table->string('alighting_verification_state', 20)->default('DRAFT');
            $table->foreignId('service_provenance_source_id')->nullable()->constrained('provenance_sources')->nullOnDelete();
            $table->foreignId('sequence_provenance_source_id')->nullable()->constrained('provenance_sources')->nullOnDelete();
            $table->foreignId('boarding_provenance_source_id')->nullable()->constrained('provenance_sources')->nullOnDelete();
            $table->foreignId('alighting_provenance_source_id')->nullable()->constrained('provenance_sources')->nullOnDelete();
            $table->index(['service_verification_state', 'route_pattern_id']);
            $table->index(['sequence_verification_state', 'route_pattern_id']);
        });

        Schema::table('transfer_relationships', function (Blueprint $table): void {
            $table->string('review_status', 20)->default(TransferReviewStatus::PENDING_REVIEW->value);
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_fingerprint', 64)->nullable();
            $table->index(['review_status', 'is_active']);
        });

        Schema::table('fares', function (Blueprint $table): void {
            $table->foreignId('route_id')->nullable()->change();
            $table->string('applicability_scope', 30)->default('ROUTE_SPECIFIC');
            $table->index(['applicability_scope', 'effective_from']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE provenance_sources ADD CONSTRAINT provenance_sources_conflict_status_valid CHECK (conflict_status IN ('NONE', 'OPEN', 'RESOLVED'))");
            DB::statement("ALTER TABLE stops ADD CONSTRAINT stops_boarding_precision_valid CHECK (boarding_precision IN ('EXACT_BOARDING_POINT', 'BOARDING_AREA', 'APPROXIMATE_LOCATION', 'UNKNOWN'))");
            DB::statement("ALTER TABLE stops ADD CONSTRAINT stops_boarding_verification_state_valid CHECK (boarding_verification_state IN ('DRAFT', 'UNVERIFIED', 'VERIFIED', 'STALE', 'RETIRED'))");
            foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                DB::statement("ALTER TABLE route_pattern_stops ADD CONSTRAINT route_pattern_stops_{$fact}_verification_state_valid CHECK ({$fact}_verification_state IN ('DRAFT', 'UNVERIFIED', 'VERIFIED', 'STALE', 'RETIRED'))");
            }
            DB::statement("ALTER TABLE transfer_relationships ADD CONSTRAINT transfer_relationships_review_status_valid CHECK (review_status IN ('PENDING_REVIEW', 'SUPPORTED', 'REJECTED', 'NEEDS_RECHECK'))");
            DB::statement("ALTER TABLE fares ADD CONSTRAINT fares_applicability_scope_valid CHECK ((applicability_scope = 'ROUTE_SPECIFIC' AND route_id IS NOT NULL) OR (applicability_scope = 'VEHICLE_CLASS' AND route_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fares DROP CONSTRAINT IF EXISTS fares_applicability_scope_valid');
            DB::statement('ALTER TABLE transfer_relationships DROP CONSTRAINT IF EXISTS transfer_relationships_review_status_valid');
            foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                DB::statement("ALTER TABLE route_pattern_stops DROP CONSTRAINT IF EXISTS route_pattern_stops_{$fact}_verification_state_valid");
            }
            DB::statement('ALTER TABLE stops DROP CONSTRAINT IF EXISTS stops_boarding_verification_state_valid');
            DB::statement('ALTER TABLE stops DROP CONSTRAINT IF EXISTS stops_boarding_precision_valid');
            DB::statement('ALTER TABLE provenance_sources DROP CONSTRAINT IF EXISTS provenance_sources_conflict_status_valid');
        }

        Schema::table('transfer_relationships', function (Blueprint $table): void {
            $table->dropIndex(['review_status', 'is_active']);
            $table->dropColumn(['review_status', 'review_notes', 'reviewed_at', 'review_fingerprint']);
        });

        DB::table('fares')->where('applicability_scope', 'VEHICLE_CLASS')->delete();
        Schema::table('fares', function (Blueprint $table): void {
            $table->dropIndex(['applicability_scope', 'effective_from']);
            $table->dropColumn('applicability_scope');
            $table->foreignId('route_id')->nullable(false)->change();
        });

        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            foreach (['service', 'sequence'] as $fact) {
                $table->dropIndex(["{$fact}_verification_state", 'route_pattern_id']);
            }
            foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                $table->dropForeign(["{$fact}_provenance_source_id"]);
            }
            $table->dropColumn([
                'service_verification_state',
                'sequence_verification_state',
                'boarding_verification_state',
                'alighting_verification_state',
                'service_provenance_source_id',
                'sequence_provenance_source_id',
                'boarding_provenance_source_id',
                'alighting_provenance_source_id',
            ]);
        });

        Schema::table('stops', function (Blueprint $table): void {
            $table->dropIndex(['boarding_precision', 'boarding_verification_state']);
            $table->dropForeign(['boarding_provenance_source_id']);
            $table->dropColumn(['boarding_precision', 'boarding_verification_state', 'boarding_provenance_source_id']);
        });

        Schema::table('provenance_sources', function (Blueprint $table): void {
            $table->dropIndex(['conflict_status', 'checked_at']);
            $table->dropIndex(['conflict_group']);
            $table->dropForeign(['superseded_by_id']);
            $table->dropColumn([
                'published_at',
                'observed_at',
                'evidence_scope',
                'conflict_status',
                'conflict_group',
                'superseded_by_id',
            ]);
        });
    }
};
