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
        Schema::create('transfer_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stop_id')->constrained('stops')->cascadeOnDelete();
            $table->foreignId('to_stop_id')->constrained('stops')->cascadeOnDelete();
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stable_id', 150)->unique();
            $table->string('relationship_type', 50);
            $table->decimal('distance_meters', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->boolean('is_demo')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['from_stop_id', 'to_stop_id']);
            $table->index(['city_id', 'is_active', 'verification_state']);
            $table->index(['from_stop_id', 'to_stop_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE transfer_relationships ADD CONSTRAINT transfer_relationships_verification_state_valid CHECK (verification_state IN ('DRAFT', 'UNVERIFIED', 'VERIFIED', 'STALE', 'RETIRED'))");
            DB::statement('ALTER TABLE transfer_relationships ADD CONSTRAINT transfer_relationships_stops_distinct CHECK (from_stop_id <> to_stop_id)');
            DB::statement('ALTER TABLE transfer_relationships ADD CONSTRAINT transfer_relationships_distance_valid CHECK (distance_meters IS NULL OR distance_meters >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_relationships');
    }
};
