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
        Schema::table('stops', function (Blueprint $table): void {
            $table->string('boarding_side', 100)->nullable();
            $table->text('direction_note')->nullable();
        });

        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->string('verification_state', 20)->default(VerificationState::DRAFT->value);
            $table->index(['verification_state', 'route_pattern_id']);
        });

        Schema::table('transfer_relationships', function (Blueprint $table): void {
            $table->foreignId('from_route_pattern_id')->nullable()->constrained('route_patterns')->nullOnDelete();
            $table->foreignId('to_route_pattern_id')->nullable()->constrained('route_patterns')->nullOnDelete();
            $table->string('landmark')->nullable();
            $table->text('practical_note')->nullable();
            $table->index(['from_route_pattern_id', 'to_route_pattern_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE route_pattern_stops ADD CONSTRAINT route_pattern_stops_verification_state_valid CHECK (verification_state IN ('DRAFT', 'UNVERIFIED', 'VERIFIED', 'STALE', 'RETIRED'))");
        }
    }

    public function down(): void
    {
        Schema::table('transfer_relationships', function (Blueprint $table): void {
            $table->dropForeign(['from_route_pattern_id']);
            $table->dropForeign(['to_route_pattern_id']);
            $table->dropIndex(['from_route_pattern_id', 'to_route_pattern_id']);
            $table->dropColumn(['from_route_pattern_id', 'to_route_pattern_id', 'landmark', 'practical_note']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE route_pattern_stops DROP CONSTRAINT IF EXISTS route_pattern_stops_verification_state_valid');
        }

        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->dropIndex(['verification_state', 'route_pattern_id']);
            $table->dropColumn('verification_state');
        });

        Schema::table('stops', function (Blueprint $table): void {
            $table->dropColumn(['boarding_side', 'direction_note']);
        });
    }
};
