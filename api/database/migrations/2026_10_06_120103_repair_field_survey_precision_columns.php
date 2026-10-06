<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['field_observations', 'survey_trace_points'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'location_precision')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('location_precision', 30)->default('UNKNOWN');
                });
            }
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['field_observations', 'survey_trace_points'] as $tableName) {
            $constraintName = $tableName.'_precision_valid';
            $constraintExists = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conname = ?',
                [$constraintName],
            ) !== null;
            if (! $constraintExists) {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} CHECK (location_precision IN ('POINT_ESTIMATE', 'BOARDING_AREA', 'LANDMARK', 'GENERAL_AREA', 'UNKNOWN'))");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['field_observations', 'survey_trace_points'] as $tableName) {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_precision_valid");
            }
        }

        foreach (['field_observations', 'survey_trace_points'] as $tableName) {
            if (Schema::hasColumn($tableName, 'location_precision')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('location_precision');
                });
            }
        }
    }
};
