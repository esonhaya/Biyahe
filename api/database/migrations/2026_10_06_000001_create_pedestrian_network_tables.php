<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedestrian_network_datasets', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->string('source', 40);
            $table->string('source_url', 2048);
            $table->string('license', 40);
            $table->string('attribution', 180);
            $table->timestamp('source_timestamp')->nullable();
            $table->timestamp('imported_at');
            $table->decimal('min_latitude', 10, 7);
            $table->decimal('min_longitude', 10, 7);
            $table->decimal('max_latitude', 10, 7);
            $table->decimal('max_longitude', 10, 7);
            $table->unsignedInteger('node_count');
            $table->unsignedInteger('edge_count');
            $table->char('content_hash', 64)->unique();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('graph_retained')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'imported_at']);
        });

        Schema::create('pedestrian_network_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dataset_id')->constrained('pedestrian_network_datasets')->cascadeOnDelete();
            $table->unsignedBigInteger('osm_node_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_walkable')->default(true);
            $table->string('barrier', 40)->nullable();
            $table->unique(['dataset_id', 'osm_node_id']);
            $table->index(['dataset_id', 'latitude', 'longitude'], 'pedestrian_node_bbox_idx');
        });

        Schema::create('pedestrian_network_edges', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_id', 150)->unique();
            $table->foreignId('dataset_id')->constrained('pedestrian_network_datasets')->cascadeOnDelete();
            $table->unsignedBigInteger('osm_way_id');
            $table->unsignedInteger('segment_index');
            $table->foreignId('from_node_id')->constrained('pedestrian_network_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('pedestrian_network_nodes')->cascadeOnDelete();
            $table->boolean('bidirectional')->default(true);
            $table->decimal('length_meters', 10, 2);
            $table->string('highway', 40);
            $table->json('properties')->nullable();
            $table->decimal('min_latitude', 10, 7);
            $table->decimal('min_longitude', 10, 7);
            $table->decimal('max_latitude', 10, 7);
            $table->decimal('max_longitude', 10, 7);
            $table->unique(['dataset_id', 'osm_way_id', 'segment_index'], 'pedestrian_edge_segment_unique');
            $table->index(['dataset_id', 'min_latitude', 'max_latitude', 'min_longitude', 'max_longitude'], 'pedestrian_edge_bbox_idx');
            $table->index(['dataset_id', 'from_node_id', 'to_node_id'], 'pedestrian_edge_nodes_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pedestrian_network_nodes ADD COLUMN location geography(Point,4326) NOT NULL');
            DB::statement('ALTER TABLE pedestrian_network_edges ADD COLUMN geometry geometry(LineString,4326) NOT NULL');
            DB::statement('CREATE INDEX pedestrian_nodes_location_gix ON pedestrian_network_nodes USING GIST (location)');
            DB::statement('CREATE INDEX pedestrian_edges_geometry_gix ON pedestrian_network_edges USING GIST (geometry)');
            DB::statement('ALTER TABLE pedestrian_network_edges ADD CONSTRAINT pedestrian_edge_length_valid CHECK (length_meters > 0 AND length_meters <= 10000)');
        } else {
            Schema::table('pedestrian_network_nodes', function (Blueprint $table): void {
                $table->text('location')->nullable();
            });
            Schema::table('pedestrian_network_edges', function (Blueprint $table): void {
                $table->text('geometry')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pedestrian_network_edges');
        Schema::dropIfExists('pedestrian_network_nodes');
        Schema::dropIfExists('pedestrian_network_datasets');
    }
};
