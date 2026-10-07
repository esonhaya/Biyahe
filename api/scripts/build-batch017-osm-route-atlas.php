#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the Batch 017 bounded Metro Cebu route-atlas dataset from OSM PTv2
 * relation snapshots.
 *
 * This script deliberately produces a conservative dataset. OSM relation
 * identity, mapped geometry, and platform locations are recorded with
 * provenance; current operation and passenger boarding/alighting practice
 * remain UNVERIFIED. XML is cached outside the repository and is never
 * committed.
 */

$options = getopt('', [
    'base::',
    'cache-dir::',
    'fetch',
    'output::',
]);

$scriptRoot = dirname(__DIR__);
$basePath = (string) ($options['base'] ?? $scriptRoot.'/database/data/cebu-network-expansion-2026-10-05.json');
$cacheDir = (string) ($options['cache-dir'] ?? sys_get_temp_dir().'/biyahe-batch017-osm-cache');
$outputPath = (string) ($options['output'] ?? $scriptRoot.'/database/data/cebu-network-atlas-2026-10-07.json');
$shouldFetch = array_key_exists('fetch', $options);

if (! is_file($basePath)) {
    fwrite(STDERR, "Base dataset not found: {$basePath}\n");
    exit(1);
}

$base = json_decode((string) file_get_contents($basePath), true, 512, JSON_THROW_ON_ERROR);
$centralPath = dirname($basePath).'/cebu-central-network-2026-10-05.json';
$central = is_file($centralPath)
    ? json_decode((string) file_get_contents($centralPath), true, 512, JSON_THROW_ON_ERROR)
    : [];
$baseSources = uniqueRecords(array_merge($central['sources'] ?? [], $base['sources'] ?? []));
$baseCities = uniqueRecords(array_merge($central['cities'] ?? [], $base['cities'] ?? []));
$basePlaces = uniqueRecords(array_merge($central['places'] ?? [], $base['places'] ?? []));
$baseAliases = uniqueRecords(array_merge($central['aliases'] ?? [], $base['aliases'] ?? []));

if ($baseSources === [] || $baseCities === []) {
    fwrite(STDERR, "The base dataset must contain source-osm-cebu-batch004 and city-cebu-city.\n");
    exit(1);
}

/**
 * Relation candidates are intentionally explicit and bounded. They are
 * high-value Cebu City/core Metro Cebu corridors listed in the reviewed OSM
 * Metro Cebu transport index. A relation is omitted if it has no usable
 * ordered platform/stop members or usable geometry.
 *
 * @var list<array{relation_id:int, code:string, vehicle_type:string}>
 */
$candidates = [
    ['relation_id' => 20587180, 'code' => '04M', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 20587181, 'code' => '04M', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267105, 'code' => '07B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267106, 'code' => '07B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267103, 'code' => '09C', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267104, 'code' => '09C', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21241368, 'code' => '09G', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21241369, 'code' => '09G', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267097, 'code' => '10H', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267098, 'code' => '10H', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267095, 'code' => '10M', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267096, 'code' => '10M', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21363724, 'code' => '11A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21363725, 'code' => '11A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251281, 'code' => '13B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251282, 'code' => '13B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3207109, 'code' => '20A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3207166, 'code' => '20A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3208008, 'code' => '22D', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3208066, 'code' => '22A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3207277, 'code' => '22I', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251272, 'code' => '04B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251271, 'code' => '04B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251270, 'code' => '04C', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251269, 'code' => '04C', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21254495, 'code' => '04D', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21254496, 'code' => '04D', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21256982, 'code' => '04H', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21256981, 'code' => '04H', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21256980, 'code' => '04I', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21256979, 'code' => '04I', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267111, 'code' => '06F', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21267112, 'code' => '06F', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21142707, 'code' => '06G', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21142708, 'code' => '06G', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251289, 'code' => '01K', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251290, 'code' => '01K', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251279, 'code' => '03A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251280, 'code' => '03A', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251287, 'code' => '02B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 21251288, 'code' => '02B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 20587182, 'code' => '01B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 20587183, 'code' => '01B', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3203006, 'code' => '12D', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3202888, 'code' => '10F', 'vehicle_type' => 'JEEPNEY'],
    ['relation_id' => 3202907, 'code' => '10G', 'vehicle_type' => 'JEEPNEY'],
];

if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0775, true) && ! is_dir($cacheDir)) {
    fwrite(STDERR, "Unable to create cache directory: {$cacheDir}\n");
    exit(1);
}

$sourceId = 'source-osm-cebu-batch017';
$checkedAt = '2026-10-07';
$source = [
    'stable_id' => $sourceId,
    'source_type' => 'COMMUNITY_OPEN_DATA',
    'title' => 'OpenStreetMap Metro Cebu PTv2 route relations — Batch 017 route atlas',
    'publisher' => 'OpenStreetMap contributors',
    'url' => 'https://wiki.openstreetmap.org/wiki/Public_transportation_in_Metro_Cebu',
    'accessed_at' => $checkedAt,
    'observed_at' => $checkedAt,
    'checked_at' => $checkedAt,
    'evidence_scope' => [
        'PLACE_LOCATION',
        'ROUTE_IDENTITY',
        'ROUTE_PATTERN',
        'ROUTE_GEOMETRY',
        'STOP_LOCATION',
    ],
    'conflict_status' => 'NONE',
    'license' => 'ODbL-1.0',
    'verification_method' => 'Fetched the explicitly listed OSM API relation/full snapshots and inspected PTv2 tags, ordered platform/stop members, and way geometry.',
    'verified_by' => 'Biyahe Batch 017 research',
    'notes' => 'This source supports mapped route identity, mapped corridor geometry, and stop/platform location only. Current operation, passenger service, sequence, boarding practice, alighting practice, fares, and exact waiting side remain UNVERIFIED. Relation IDs are recorded in the generated route/pattern metadata. OpenStreetMap data is used under ODbL-1.0 with attribution to OpenStreetMap contributors.',
];

$routes = [];
$patterns = [];
$stops = [];
$stopByNode = [];
$routeCodes = [];
$existingStops = [];
$existingStopByCode = [];
foreach (array_merge($central['stops'] ?? [], $base['stops'] ?? []) as $existingStop) {
    if (is_array($existingStop) && is_string($existingStop['stable_id'] ?? null)) {
        $existingStops[$existingStop['stable_id']] = $existingStop;
        if (is_string($existingStop['code'] ?? null) && $existingStop['code'] !== '') {
            $existingStopByCode[$existingStop['code']] = $existingStop['stable_id'];
        }
    }
}
$loaded = 0;
$skipped = [];

foreach ($candidates as $index => $candidate) {
    $relationId = (int) $candidate['relation_id'];
    $cachePath = $cacheDir.'/relation-'.$relationId.'.osm';
    if ($shouldFetch || ! is_file($cachePath) || filesize($cachePath) === 0) {
        $url = 'https://api.openstreetmap.org/api/0.6/relation/'.$relationId.'/full';
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'ignore_errors' => false,
                'header' => "User-Agent: Biyahe-Batch017-route-atlas/1.0 (source research)\r\nAccept: application/xml\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || trim($body) === '') {
            $skipped[] = $relationId.': download failed';
            continue;
        }
        file_put_contents($cachePath, $body, LOCK_EX);
        // Respect the public API with a small bounded pause between relation requests.
        if ($index < count($candidates) - 1) {
            usleep(350000);
        }
    }

    $xml = @simplexml_load_file($cachePath);
    if ($xml === false) {
        $skipped[] = $relationId.': invalid XML';
        continue;
    }
    $relationNodes = $xml->xpath("//relation[@id='{$relationId}']");
    $relation = $relationNodes[0] ?? null;
    if (! $relation instanceof SimpleXMLElement) {
        $skipped[] = $relationId.': relation not present';
        continue;
    }

    $nodeMap = [];
    foreach ($xml->node as $node) {
        $nodeId = (string) ($node['id'] ?? '');
        if ($nodeId === '') {
            continue;
        }
        $tags = tags($node->tag ?? []);
        $nodeMap[$nodeId] = [
            'lat' => (float) $node['lat'],
            'lng' => (float) $node['lon'],
            'tags' => $tags,
        ];
    }

    $wayMap = [];
    foreach ($xml->way as $way) {
        $wayId = (string) ($way['id'] ?? '');
        if ($wayId === '') {
            continue;
        }
        $refs = [];
        foreach ($way->nd as $nd) {
            $ref = (string) ($nd['ref'] ?? '');
            if ($ref !== '' && isset($nodeMap[$ref])) {
                $refs[] = $ref;
            }
        }
        $wayMap[$wayId] = $refs;
    }

    $relationTags = tags($relation->tag ?? []);
    $relationsToProcess = [$relation];
    if (($relationTags['type'] ?? null) === 'route_master') {
        foreach ($xml->relation as $nestedRelation) {
            $nestedId = (int) ($nestedRelation['id'] ?? 0);
            if ($nestedId === 0 || $nestedId === $relationId) {
                continue;
            }
            $nestedTags = tags($nestedRelation->tag ?? []);
            if (($nestedTags['type'] ?? null) === 'route'
                && strtoupper((string) ($nestedTags['ref'] ?? '')) === strtoupper($candidate['code'])) {
                $relationsToProcess[] = $nestedRelation;
            }
        }
    }

    foreach ($relationsToProcess as $routeRelation) {
        $currentRelationId = (int) ($routeRelation['id'] ?? $relationId);
        $currentTags = tags($routeRelation->tag ?? []);
        $platformMembers = [];
        $geometry = [];
        foreach ($routeRelation->member as $member) {
            $type = (string) ($member['type'] ?? '');
            $role = (string) ($member['role'] ?? '');
            $ref = (string) ($member['ref'] ?? '');
            if ($type === 'node' && preg_match('/^(platform|stop)(_|$)/', $role) === 1 && isset($nodeMap[$ref])) {
                if (! isset($platformMembers[$ref])) {
                    $platformMembers[$ref] = [
                        'node_id' => $ref,
                        'role' => $role,
                    ];
                }
            }
            if ($type === 'way' && isset($wayMap[$ref])) {
                $line = [];
                foreach ($wayMap[$ref] as $nodeRef) {
                    $line[] = [$nodeMap[$nodeRef]['lng'], $nodeMap[$nodeRef]['lat']];
                }
                if (count($line) >= 2) {
                    $geometry[] = $line;
                }
            }
        }

        if (count($platformMembers) < 2 || $geometry === []) {
            $skipped[] = $currentRelationId.': '.count($platformMembers).' usable platform members / '.count($geometry).' geometry lines';
            continue;
        }

        $code = strtoupper(trim($candidate['code']));
        $routeSlug = slug($code);
        $routeId = 'route-'.$routeSlug;
        $routeCodes[$code] = true;
        $routeName = trim((string) ($currentTags['name'] ?? $relationTags['name'] ?? ''));
        if ($routeName === '') {
            $routeName = 'OSM relation '.$currentRelationId;
        }
        $from = trim((string) ($currentTags['from'] ?? ''));
        $to = trim((string) ($currentTags['to'] ?? ''));
        $direction = direction($from, $to, $currentRelationId);
        $patternId = $routeId.'-osm-'.$currentRelationId;
        $patternLabel = $from !== '' || $to !== ''
            ? trim($from.' to '.$to)
            : $routeName;

        if (! isset($routes[$routeId])) {
            $routes[$routeId] = [
                'stable_id' => $routeId,
                'city_id' => 'city-cebu-city',
                'operator_id' => null,
                'vehicle_type' => $candidate['vehicle_type'],
                'source_id' => $sourceId,
                'code' => $code,
                'name' => 'Jeepney '.$code.': '.$routeName,
                'description' => 'OSM PTv2 route relation(s) mapped for the Batch 017 Metro Cebu route atlas; current operation and exact passenger service remain reviewable.',
                'is_active' => true,
                'verification_state' => 'UNVERIFIED',
                'is_demo' => false,
                'notes' => 'Mapped route identity and corridor are sourced from OSM relation data. This record does not establish schedule, current service, fare, exact boarding, or exact alighting practice.',
            ];
        }

        $patternStops = [];
        $sequence = 1;
        foreach ($platformMembers as $member) {
            $nodeId = $member['node_id'];
            $node = $nodeMap[$nodeId];
            $stopId = $existingStopByCode['OSM-'.$nodeId] ?? 'stop-osm-'.$nodeId;
            if (! isset($stopByNode[$stopId])) {
                $name = trim((string) ($node['tags']['name'] ?? ''));
                if ($name === '') {
                    $name = 'OSM platform '.$nodeId;
                }
                $stops[$stopId] = [
                    'stable_id' => $stopId,
                    'city_id' => 'city-cebu-city',
                    'source_id' => $sourceId,
                    'name' => $name,
                    'code' => 'OSM-'.$nodeId,
                    'category' => 'PLATFORM',
                    'is_boarding_point' => true,
                    'boarding_notes' => 'Mapped OpenStreetMap platform/stop member; exact waiting side, landmark, and current boarding practice need local review.',
                    'boarding_precision' => 'UNKNOWN',
                    'boarding_verification_state' => 'UNVERIFIED',
                    'boarding_source_id' => $sourceId,
                    'location' => ['lat' => $node['lat'], 'lng' => $node['lng']],
                    'is_active' => true,
                    'verification_state' => 'UNVERIFIED',
                    'is_demo' => false,
                    'notes' => 'Platform/stop node from OSM route relation '.$currentRelationId.'; not a promise that vehicles stop at this exact point today.',
                ];
                $stopByNode[$stopId] = true;
            }
            $patternStops[] = [
                'stable_id' => $patternId.'-stop-'.$sequence,
                'stop_id' => $stopId,
                'source_id' => $sourceId,
                'sequence' => $sequence,
                'boarding_allowed' => true,
                'alighting_allowed' => true,
                'verification_state' => 'UNVERIFIED',
                'service_verification_state' => 'UNVERIFIED',
                'sequence_verification_state' => 'UNVERIFIED',
                'boarding_verification_state' => 'UNVERIFIED',
                'alighting_verification_state' => 'UNVERIFIED',
                'service_source_id' => $sourceId,
                'sequence_source_id' => $sourceId,
                'boarding_source_id' => $sourceId,
                'alighting_source_id' => $sourceId,
                'instruction' => 'Mapped platform candidate; confirm the vehicle signboard and roadside waiting side before boarding.',
                'service_metadata' => [
                    'osm_relation_id' => $currentRelationId,
                    'osm_member_role' => $member['role'],
                    'association_evidence' => 'OpenStreetMap PTv2 route relation',
                    'association_verified' => false,
                ],
            ];
            $sequence++;
        }

        $patterns[$patternId] = [
            'stable_id' => $patternId,
            'route_id' => $routeId,
            'source_id' => $sourceId,
            'name' => 'OSM relation '.$currentRelationId.': '.$patternLabel,
            'direction' => $direction,
            'service_notes' => 'OSM PTv2 directional relation '.$currentRelationId.'; mapped geometry and member order are available, but current passenger service and sequence remain UNVERIFIED.',
            'geometry' => ['type' => 'MultiLineString', 'coordinates' => $geometry],
            'verification_state' => 'UNVERIFIED',
            'is_demo' => false,
            'stops' => $patternStops,
        ];
        $loaded++;
    }
}

ksort($routes);
ksort($patterns);
ksort($stops);

// A partial dataset may touch existing OSM stop IDs. Keep their complete
// established record so an idempotent import cannot erase nearby-place,
// boarding, or provenance fields that are outside this atlas update.
foreach ($stops as $stopId => $stop) {
    if (isset($existingStops[$stopId])) {
        $stops[$stopId] = $existingStops[$stopId];
    }
}

/**
 * Named OSM platform anchors that are useful commuter destinations. Their
 * location is copied from the named platform member; they are not claims
 * about an entrance, service, or exact boarding point.
 *
 * @var list<array{stable_id:string,name:string,slug:string,category:string,stop_id:string,aliases:list<string>}>
 */
$placeSpecs = [
    ['stable_id' => 'place-apm-mall', 'name' => 'APM Mall', 'slug' => 'apm-mall', 'category' => 'MALL', 'stop_id' => 'stop-osm-10251749646', 'aliases' => ['APM Mall Cebu', 'APM']],
    ['stable_id' => 'place-cebu-doctors-hospital', 'name' => 'Cebu Doctors Hospital', 'slug' => 'cebu-doctors-hospital', 'category' => 'HOSPITAL', 'stop_id' => 'stop-osm-10149201813', 'aliases' => ['CDH']],
    ['stable_id' => 'place-colonnade-mall', 'name' => 'Colonnade Mall', 'slug' => 'colonnade-mall', 'category' => 'MALL', 'stop_id' => 'stop-osm-5804071255', 'aliases' => ['Colonnade']],
    ['stable_id' => 'place-cebu-metropolitan-cathedral', 'name' => 'Cebu Metropolitan Cathedral', 'slug' => 'cebu-metropolitan-cathedral', 'category' => 'LANDMARK', 'stop_id' => 'stop-osm-10250495120', 'aliases' => ['Cebu Cathedral']],
    ['stable_id' => 'place-cebu-exchange', 'name' => 'Cebu Exchange', 'slug' => 'cebu-exchange', 'category' => 'BUSINESS_DISTRICT', 'stop_id' => 'stop-osm-13760438072', 'aliases' => ['Cebu Exchange Building']],
    ['stable_id' => 'place-jy-square-mall', 'name' => 'JY Square Mall', 'slug' => 'jy-square-mall', 'category' => 'MALL', 'stop_id' => 'stop-osm-13760438081', 'aliases' => ['JY Square']],
    ['stable_id' => 'place-gaisano-grand-mall-talamban', 'name' => 'Gaisano Grand Mall Talamban', 'slug' => 'gaisano-grand-mall-talamban', 'category' => 'MALL', 'stop_id' => 'stop-osm-13769915540', 'aliases' => ['Gaisano Talamban']],
    ['stable_id' => 'place-bulacao', 'name' => 'Bulacao', 'slug' => 'bulacao', 'category' => 'DISTRICT', 'stop_id' => 'stop-osm-14114354083', 'aliases' => ['Bulacao Cebu']],
    ['stable_id' => 'place-inayawan-public-market', 'name' => 'Inayawan Public Market', 'slug' => 'inayawan-public-market', 'category' => 'MARKET', 'stop_id' => 'stop-osm-14158689221', 'aliases' => ['Inayawan Market']],
    ['stable_id' => 'place-pardo', 'name' => 'Pardo', 'slug' => 'pardo', 'category' => 'DISTRICT', 'stop_id' => 'stop-osm-10250473104', 'aliases' => ['Pardo Cebu']],
    ['stable_id' => 'place-banawa', 'name' => 'Banawa', 'slug' => 'banawa', 'category' => 'DISTRICT', 'stop_id' => 'stop-osm-14114354088', 'aliases' => ['Banawa Cebu']],
    ['stable_id' => 'place-gaisano-capital-south', 'name' => 'Gaisano Capital South', 'slug' => 'gaisano-capital-south', 'category' => 'MALL', 'stop_id' => 'stop-osm-10250495133', 'aliases' => ['Gaisano South']],
];
$placesById = [];
foreach ($basePlaces as $place) {
    if (is_array($place) && is_string($place['stable_id'] ?? null)) {
        $placesById[$place['stable_id']] = $place;
    }
}
$aliasesById = [];
foreach ($baseAliases as $alias) {
    if (is_array($alias) && is_string($alias['stable_id'] ?? null)) {
        $aliasesById[$alias['stable_id']] = $alias;
    }
}
foreach ($placeSpecs as $spec) {
    $stop = $stops[$spec['stop_id']] ?? null;
    if (! is_array($stop) || ! is_array($stop['location'] ?? null)) {
        continue;
    }
    $placeId = $spec['stable_id'];
    $placesById[$placeId] = [
        'stable_id' => $placeId,
        'city_id' => 'city-cebu-city',
        'source_id' => $sourceId,
        'name' => $spec['name'],
        'slug' => $spec['slug'],
        'category' => $spec['category'],
        'description' => 'Named OSM platform anchor used as a commuter search destination; exact entrance and boarding practice remain unverified.',
        'location' => $stop['location'],
        'is_active' => true,
        'verification_state' => 'VERIFIED',
        'is_demo' => false,
        'notes' => 'Location is sourced from a named OSM public-transport platform member in the Batch 017 route atlas. This does not establish an exact pedestrian entrance or transport service.',
    ];
    if (! isset($existingStops[$spec['stop_id']])) {
        $stops[$spec['stop_id']]['nearby_place_id'] = $placeId;
    }
    foreach ($spec['aliases'] as $aliasIndex => $aliasText) {
        $aliasId = 'alias-batch017-'.$spec['slug'].'-'.$aliasIndex;
        $aliasesById[$aliasId] = [
            'stable_id' => $aliasId,
            'place_id' => $placeId,
            'source_id' => $sourceId,
            'alias' => $aliasText,
            'is_active' => true,
        ];
    }
}
ksort($placesById);
ksort($aliasesById);

$output = [
    'format' => 'biyahe-transport-dataset',
    'version' => 1,
    'dataset_id' => 'cebu-network-atlas-2026-10-07',
    'license' => [
        'name' => 'ODbL-1.0',
        'attribution' => '© OpenStreetMap contributors',
        'notes' => 'OSM-derived route geometry and platform locations are imported under the Open Database License. See the source registry for scope limitations.',
    ],
    'sources' => array_values(array_merge($baseSources, [$source])),
    'cities' => $baseCities,
    'places' => array_values($placesById),
    'aliases' => array_values($aliasesById),
    'stops' => array_values($stops),
    'operators' => [],
    'routes' => array_values($routes),
    'patterns' => array_values($patterns),
    'transfers' => [],
    'fares' => [],
];

if (! is_dir(dirname($outputPath)) && ! mkdir(dirname($outputPath), 0775, true) && ! is_dir(dirname($outputPath))) {
    fwrite(STDERR, "Unable to create output directory: ".dirname($outputPath)."\n");
    exit(1);
}

file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX);

fwrite(STDOUT, json_encode([
    'output' => $outputPath,
    'relations_loaded' => $loaded,
    'relations_skipped' => $skipped,
    'routes' => count($output['routes']),
    'patterns' => count($output['patterns']),
    'stops' => count($output['stops']),
    'pattern_stops' => array_sum(array_map(static fn (array $pattern): int => count($pattern['stops']), $output['patterns'])),
    'source_relations' => count($candidates),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/** @param list<mixed> $records @return list<array<string, mixed>> */
function uniqueRecords(array $records): array
{
    $unique = [];
    foreach ($records as $record) {
        if (! is_array($record) || ! is_string($record['stable_id'] ?? null)) {
            continue;
        }
        $unique[$record['stable_id']] = $record;
    }

    ksort($unique);

    return array_values($unique);
}

/** @return array<string, string> */
function tags(SimpleXMLElement|array $nodes): array
{
    $result = [];
    foreach ($nodes as $tag) {
        $key = (string) ($tag['k'] ?? '');
        if ($key !== '') {
            $result[$key] = (string) ($tag['v'] ?? '');
        }
    }

    return $result;
}

function slug(string $value): string
{
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value));

    return trim($slug, '-');
}

function direction(string $from, string $to, int $relationId): string
{
    $label = strtolower($from.' '.$to);
    if (preg_match('/\b(to|toward|towards|northbound|inbound|carbon|colon|sm city|lahug|ayala)\b/', $label) === 1) {
        return 'OUTBOUND';
    }
    if ($from !== '' || $to !== '') {
        return 'INBOUND';
    }

    return $relationId % 2 === 0 ? 'OUTBOUND' : 'INBOUND';
}
