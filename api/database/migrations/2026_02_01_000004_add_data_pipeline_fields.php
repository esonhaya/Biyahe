<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provenance_sources', function (Blueprint $table): void {
            $table->string('stable_id', 150)->nullable()->unique();
            $table->date('checked_at')->nullable()->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('license', 120)->nullable();
            $table->string('verification_method')->nullable();
            $table->string('verified_by')->nullable();
        });

        foreach (['cities', 'places', 'place_aliases', 'stops', 'operators', 'routes', 'route_patterns', 'fares', 'route_pattern_stops'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('stable_id', 150)->nullable()->unique();
            });
        }

        Schema::table('place_aliases', function (Blueprint $table): void {
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->foreignId('provenance_source_id')->nullable()->constrained()->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach (['cities', 'operators', 'places', 'stops', 'routes', 'route_patterns', 'fares'] as $tableName) {
                Schema::getConnection()->statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_verification_state_valid");
                Schema::getConnection()->statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_verification_state_valid CHECK (verification_state IN ('DRAFT', 'UNVERIFIED', 'VERIFIED', 'STALE', 'RETIRED'))");
            }

            Schema::getConnection()->statement('CREATE INDEX IF NOT EXISTS route_patterns_geometry_gix ON route_patterns USING GIST (geometry)');
        }
    }

    public function down(): void
    {
        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->dropForeign(['provenance_source_id']);
            $table->dropColumn('provenance_source_id');
        });

        Schema::table('place_aliases', function (Blueprint $table): void {
            $table->dropForeign(['provenance_source_id']);
            $table->dropColumn('provenance_source_id');
        });

        foreach (['cities', 'places', 'place_aliases', 'stops', 'operators', 'routes', 'route_patterns', 'fares', 'route_pattern_stops'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['stable_id']);
                $table->dropColumn('stable_id');
            });
        }

        Schema::table('provenance_sources', function (Blueprint $table): void {
            $table->dropUnique(['stable_id']);
            $table->dropColumn([
                'stable_id',
                'checked_at',
                'effective_from',
                'effective_until',
                'license',
                'verification_method',
                'verified_by',
            ]);
        });
    }
};
