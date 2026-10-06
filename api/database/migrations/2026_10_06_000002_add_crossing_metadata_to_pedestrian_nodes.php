<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedestrian_network_nodes', function (Blueprint $table): void {
            $table->string('crossing', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedestrian_network_nodes', function (Blueprint $table): void {
            $table->dropColumn('crossing');
        });
    }
};
