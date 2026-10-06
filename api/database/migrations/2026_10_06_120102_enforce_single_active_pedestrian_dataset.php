<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pedestrian_single_active_dataset_idx ON pedestrian_network_datasets (is_active) WHERE is_active = true');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX pedestrian_single_active_dataset_idx ON pedestrian_network_datasets (is_active) WHERE is_active = 1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX pedestrian_single_active_dataset_idx');
    }
};
