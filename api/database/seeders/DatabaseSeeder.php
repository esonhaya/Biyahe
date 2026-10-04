<?php

namespace Database\Seeders;

use App\Enums\VehicleTypeCode;
use App\Enums\VerificationState;
use App\Models\City;
use App\Models\Fare;
use App\Models\Operator;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\VehicleType;
use App\Services\Data\DatasetImporter;
use App\Services\Data\DatasetValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $fixtureSource = ProvenanceSource::updateOrCreate(
            ['source_type' => 'DEMO_FIXTURE', 'title' => 'Biyahe Batch 001 development fixture'],
            [
                'publisher' => 'Biyahe project',
                'accessed_at' => now()->toDateString(),
                'notes' => 'Artificial records for structural and UI verification. Not authoritative transport information.',
            ],
        );

        $city = City::updateOrCreate(
            ['slug' => 'cebu-city'],
            [
                'name' => 'Cebu City',
                'country_code' => 'PH',
                'is_active' => true,
                'verification_state' => VerificationState::DRAFT,
                'is_demo' => true,
                'provenance_source_id' => $fixtureSource->id,
                'notes' => 'Initial development service area; fixture only.',
            ],
        );

        foreach (VehicleTypeCode::cases() as $vehicleType) {
            VehicleType::updateOrCreate(
                ['code' => $vehicleType->value],
                [
                    'name' => Str::headline(strtolower($vehicleType->value)),
                    'description' => in_array($vehicleType, [VehicleTypeCode::JEEPNEY, VehicleTypeCode::BUS], true)
                        ? 'Supported in the Batch 001 transport foundation.'
                        : 'Reserved for a future transport batch.',
                    'is_active' => in_array($vehicleType, [VehicleTypeCode::JEEPNEY, VehicleTypeCode::BUS], true),
                ],
            );
        }

        $operator = Operator::updateOrCreate(
            ['slug' => 'demo-independent-operator'],
            [
                'name' => 'DEMO: Independent Operator',
                'description' => 'Artificial operator used only by the development fixture.',
                'is_active' => true,
                'verification_state' => VerificationState::DRAFT,
                'is_demo' => true,
                'provenance_source_id' => $fixtureSource->id,
            ],
        );

        $places = collect([
            ['name' => 'DEMO: Fuente Civic Landmark', 'slug' => 'demo-fuente-civic-landmark', 'category' => 'LANDMARK', 'description' => 'Artificial landmark fixture.', 'location' => ['lat' => 10.3157, 'lng' => 123.8854]],
            ['name' => 'DEMO: Central Market', 'slug' => 'demo-central-market', 'category' => 'MARKET', 'description' => 'Artificial market fixture.', 'location' => ['lat' => 10.3074, 'lng' => 123.8932]],
            ['name' => 'DEMO: University Gate', 'slug' => 'demo-university-gate', 'category' => 'UNIVERSITY', 'description' => 'Artificial university destination fixture.', 'location' => ['lat' => 10.3267, 'lng' => 123.9068]],
        ])->mapWithKeys(function (array $place) use ($city, $fixtureSource): array {
            $record = Place::updateOrCreate(
                ['city_id' => $city->id, 'slug' => $place['slug']],
                $place + [
                    'city_id' => $city->id,
                    'verification_state' => VerificationState::DRAFT,
                    'is_demo' => true,
                    'is_active' => true,
                    'provenance_source_id' => $fixtureSource->id,
                ],
            );

            return [$place['slug'] => $record];
        });

        foreach ([
            'demo-fuente-civic-landmark' => ['DEMO Fuente', 'DEMO Civic Circle'],
            'demo-central-market' => ['DEMO Market'],
            'demo-university-gate' => ['DEMO University'],
        ] as $slug => $names) {
            foreach ($names as $alias) {
                $places[$slug]->aliases()->updateOrCreate(
                    ['normalized_alias' => Str::lower($alias)],
                    ['alias' => $alias, 'is_active' => true],
                );
            }
        }

        $stops = collect([
            ['name' => 'DEMO Stop A — Civic Landmark', 'code' => 'DEMO-A', 'nearby_place_id' => $places['demo-fuente-civic-landmark']->id, 'location' => ['lat' => 10.3159, 'lng' => 123.8857], 'boarding_notes' => 'Fixture boarding point beside the demo landmark.'],
            ['name' => 'DEMO Stop B — Central Market', 'code' => 'DEMO-B', 'nearby_place_id' => $places['demo-central-market']->id, 'location' => ['lat' => 10.3076, 'lng' => 123.8930], 'boarding_notes' => 'Fixture boarding point beside the demo market.'],
            ['name' => 'DEMO Stop C — University Gate', 'code' => 'DEMO-C', 'nearby_place_id' => $places['demo-university-gate']->id, 'location' => ['lat' => 10.3265, 'lng' => 123.9065], 'boarding_notes' => 'Fixture boarding point near the demo university.'],
            ['name' => 'DEMO Stop D — Shared Junction', 'code' => 'DEMO-D', 'nearby_place_id' => null, 'location' => ['lat' => 10.3190, 'lng' => 123.8970], 'boarding_notes' => 'Fixture shared stop demonstrating route overlap.'],
        ])->mapWithKeys(function (array $stop) use ($city, $fixtureSource): array {
            $record = Stop::updateOrCreate(
                ['city_id' => $city->id, 'code' => $stop['code']],
                $stop + [
                    'city_id' => $city->id,
                    'category' => 'GENERAL',
                    'is_boarding_point' => true,
                    'is_active' => true,
                    'verification_state' => VerificationState::DRAFT,
                    'is_demo' => true,
                    'provenance_source_id' => $fixtureSource->id,
                ],
            );

            return [$stop['code'] => $record];
        });

        $jeepney = VehicleType::where('code', VehicleTypeCode::JEEPNEY->value)->firstOrFail();
        $bus = VehicleType::where('code', VehicleTypeCode::BUS->value)->firstOrFail();
        $routeDefinitions = [
            [
                'code' => 'DEMO-04L',
                'name' => 'DEMO: Civic Loop',
                'vehicle_type_id' => $jeepney->id,
                'patterns' => [
                    ['name' => 'Outbound', 'direction' => 'OUTBOUND', 'stops' => ['DEMO-A', 'DEMO-D', 'DEMO-C'], 'geometry' => 'SRID=4326;MULTILINESTRING((123.8857 10.3159,123.8970 10.3190,123.9065 10.3265))'],
                    ['name' => 'Inbound', 'direction' => 'INBOUND', 'stops' => ['DEMO-C', 'DEMO-D', 'DEMO-A'], 'geometry' => 'SRID=4326;MULTILINESTRING((123.9065 10.3265,123.8970 10.3190,123.8857 10.3159))'],
                ],
            ],
            [
                'code' => 'DEMO-17B',
                'name' => 'DEMO: Market Connector',
                'vehicle_type_id' => $bus->id,
                'patterns' => [
                    ['name' => 'Market to Junction', 'direction' => 'OUTBOUND', 'stops' => ['DEMO-B', 'DEMO-D', 'DEMO-C'], 'geometry' => 'SRID=4326;MULTILINESTRING((123.8930 10.3076,123.8970 10.3190,123.9065 10.3265))'],
                ],
            ],
        ];

        foreach ($routeDefinitions as $definition) {
            $route = Route::updateOrCreate(
                ['city_id' => $city->id, 'code' => $definition['code']],
                [
                    'operator_id' => $operator->id,
                    'vehicle_type_id' => $definition['vehicle_type_id'],
                    'provenance_source_id' => $fixtureSource->id,
                    'name' => $definition['name'],
                    'description' => 'Artificial route fixture for Batch 001.',
                    'is_active' => true,
                    'verification_state' => VerificationState::DRAFT,
                    'is_demo' => true,
                ],
            );

            foreach ($definition['patterns'] as $patternDefinition) {
                $pattern = RoutePattern::updateOrCreate(
                    ['route_id' => $route->id, 'name' => $patternDefinition['name']],
                    [
                        'direction' => $patternDefinition['direction'],
                        'service_notes' => 'Fixture pattern; no formal schedule is claimed.',
                        'geometry' => $patternDefinition['geometry'],
                        'is_active' => true,
                        'verification_state' => VerificationState::DRAFT,
                        'is_demo' => true,
                        'provenance_source_id' => $fixtureSource->id,
                    ],
                );

                foreach ($patternDefinition['stops'] as $index => $stopCode) {
                    RoutePatternStop::updateOrCreate(
                        ['route_pattern_id' => $pattern->id, 'sequence' => $index + 1],
                        [
                            'stop_id' => $stops[$stopCode]->id,
                            'boarding_allowed' => true,
                            'alighting_allowed' => true,
                            'instruction' => 'Fixture instruction for sequence '.($index + 1).'.',
                            'service_metadata' => ['fixture' => true],
                        ],
                    );
                }
            }

            Fare::updateOrCreate(
                ['route_id' => $route->id, 'fare_class' => 'REGULAR', 'effective_from' => '2026-01-01'],
                [
                    'vehicle_type_id' => $definition['vehicle_type_id'],
                    'amount' => 15.00,
                    'currency' => 'PHP',
                    'verification_state' => VerificationState::DRAFT,
                    'is_demo' => true,
                    'provenance_source_id' => $fixtureSource->id,
                    'notes' => 'Artificial fare foundation record; not a real fare estimate.',
                ],
            );
        }

        $dataset = app(DatasetValidator::class)->validate(database_path('data/cebu-central-network-2026-10-05.json'));
        if (! $dataset->isValid() || $dataset->data === null) {
            throw new RuntimeException('The reviewed Cebu dataset failed validation while seeding.');
        }

        app(DatasetImporter::class)->import($dataset->data);
    }
}
