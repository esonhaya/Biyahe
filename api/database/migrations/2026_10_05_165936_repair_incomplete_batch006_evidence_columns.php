<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transfer_relationships', function (Blueprint $table): void {
            if (! Schema::hasColumn('transfer_relationships', 'review_notes')) {
                $table->text('review_notes')->nullable();
            }
            if (! Schema::hasColumn('transfer_relationships', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('transfer_relationships', 'review_fingerprint')) {
                $table->string('review_fingerprint', 64)->nullable();
            }
        });

        $addsApplicabilityScope = ! Schema::hasColumn('fares', 'applicability_scope');
        if ($addsApplicabilityScope) {
            Schema::table('fares', function (Blueprint $table): void {
                $table->string('applicability_scope', 30)->default('ROUTE_SPECIFIC');
            });
            DB::table('fares')->whereNull('route_id')->update(['applicability_scope' => 'VEHICLE_CLASS']);
        }

        if (! Schema::hasIndex('fares', ['applicability_scope', 'effective_from'])) {
            Schema::table('fares', function (Blueprint $table): void {
                $table->index(['applicability_scope', 'effective_from']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
