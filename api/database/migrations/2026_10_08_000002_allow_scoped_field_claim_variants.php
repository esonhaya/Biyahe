<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_mission_claims', function (Blueprint $table): void {
            $table->dropUnique('field_mission_claim_scope_unique');
            $table->unique(['field_mission_id', 'stable_id'], 'field_mission_claim_mission_stable_unique');
        });
    }

    public function down(): void
    {
        Schema::table('field_mission_claims', function (Blueprint $table): void {
            $table->dropUnique('field_mission_claim_mission_stable_unique');
            $table->unique(['field_mission_id', 'target_stable_id', 'claim_type'], 'field_mission_claim_scope_unique');
        });
    }
};
