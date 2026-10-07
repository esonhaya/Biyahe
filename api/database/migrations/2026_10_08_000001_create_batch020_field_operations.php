<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_missions', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->string('title', 180);
            $table->string('area', 180);
            $table->string('status', 30)->default('PLANNED');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->json('claim_checklist');
            $table->json('route_scope')->nullable();
            $table->text('instructions')->nullable();
            $table->text('safety_notes')->nullable();
            $table->string('source_campaign_version', 100)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_synthetic')->default(false);
            $table->string('import_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'priority']);
            $table->index(['is_synthetic', 'status']);
        });

        Schema::create('field_mission_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->foreignId('field_mission_id')->constrained()->cascadeOnDelete();
            $table->string('claim_type', 50);
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_stable_id', 150);
            $table->text('prompt');
            $table->string('current_status', 30)->default('OPEN');
            $table->json('affected_od_pairs')->nullable();
            $table->unsignedInteger('journeys_affected')->default(0);
            $table->integer('priority_score')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['field_mission_id', 'target_stable_id', 'claim_type'], 'field_mission_claim_scope_unique');
            $table->index(['field_mission_id', 'current_status']);
            $table->index(['target_type', 'target_stable_id']);
        });

        Schema::table('transport_survey_sessions', function (Blueprint $table): void {
            $table->foreignId('field_mission_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
            $table->index(['field_mission_id', 'status']);
        });

        Schema::table('field_observations', function (Blueprint $table): void {
            $table->foreignId('field_mission_claim_id')->nullable()->after('survey_session_id')->constrained()->nullOnDelete();
            $table->boolean('sync_conflict')->default(false)->after('review_status');
            $table->unsignedSmallInteger('client_schema_version')->default(1)->after('sync_conflict');
            $table->index(['field_mission_claim_id', 'review_status']);
            $table->index(['sync_conflict', 'review_status']);
        });

        Schema::create('field_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->foreignId('field_mission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('field_mission_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('field_observation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('field_evidence_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamps();
            $table->index(['field_mission_id', 'created_at']);
            $table->index(['field_evidence_claim_id', 'action']);
            $table->index(['field_observation_id', 'action']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_field_operator')->default(false)->after('password');
            $table->index('is_field_operator');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_field_operator']);
            $table->dropColumn('is_field_operator');
        });
        Schema::dropIfExists('field_review_actions');
        Schema::table('field_observations', function (Blueprint $table): void {
            $table->dropIndex(['sync_conflict', 'review_status']);
            $table->dropIndex(['field_mission_claim_id', 'review_status']);
            $table->dropForeign(['field_mission_claim_id']);
            $table->dropColumn(['field_mission_claim_id', 'sync_conflict', 'client_schema_version']);
        });
        Schema::table('transport_survey_sessions', function (Blueprint $table): void {
            $table->dropIndex(['field_mission_id', 'status']);
            $table->dropForeign(['field_mission_id']);
            $table->dropColumn('field_mission_id');
        });
        Schema::dropIfExists('field_mission_claims');
        Schema::dropIfExists('field_missions');
    }
};
