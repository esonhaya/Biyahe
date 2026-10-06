@php
    $routeLines = $route_lines ?? [];
    $mappedStops = $mapped_stops ?? [];
    $trace = $trace_points ?? [];
    $observed = $observations ?? [];
    $jumps = $potential_jumps ?? [];
    $routeCoordinates = [];
    foreach ($routeLines as $line) {
        $routeCoordinates = array_merge($routeCoordinates, $line);
    }
    $stopCoordinates = array_values(array_filter(array_column($mappedStops, 'point')));
    $traceCoordinates = array_values(array_filter(array_column($trace, 'point')));
    $observationCoordinates = array_values(array_filter(array_column($observed, 'point')));
    $coordinates = array_merge($routeCoordinates, $stopCoordinates, $traceCoordinates, $observationCoordinates);
    $latitudes = array_column($coordinates, 'lat');
    $longitudes = array_column($coordinates, 'lng');
    $minLatitude = $latitudes === [] ? 0 : min($latitudes);
    $maxLatitude = $latitudes === [] ? 0 : max($latitudes);
    $minLongitude = $longitudes === [] ? 0 : min($longitudes);
    $maxLongitude = $longitudes === [] ? 0 : max($longitudes);
    $latitudeSpan = max($maxLatitude - $minLatitude, 0.00001);
    $longitudeSpan = max($maxLongitude - $minLongitude, 0.00001);
    $position = fn (array $point): string => sprintf(
        '%.1f,%.1f',
        24 + (($point['lng'] - $minLongitude) / $longitudeSpan * 552),
        18 + (($maxLatitude - $point['lat']) / $latitudeSpan * 224),
    );
@endphp

<div class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
        Current mapped route geometry and stops are gray; field samples are blue; observation locations are amber. Lines show recorded order only and are not map-matched or pedestrian routes.
    </p>
    @if (count($coordinates) > 0)
        <svg viewBox="0 0 600 260" role="img" aria-label="Survey observations and ordered GPS trace" class="w-full max-h-96 rounded-lg bg-gray-50 dark:bg-gray-800">
            @foreach ($routeLines as $line)
                @if (count($line) > 1)
                    <polyline points="{{ implode(' ', array_map($position, $line)) }}" fill="none" stroke="#6b7280" stroke-width="4" />
                @endif
            @endforeach
            @if (count($traceCoordinates) > 1)
                <polyline points="{{ implode(' ', array_map($position, $traceCoordinates)) }}" fill="none" stroke="#2563eb" stroke-width="3" stroke-dasharray="6 4" />
            @endif
            @foreach ($jumps as $jump)
                @php [$fromHorizontal, $fromVertical] = explode(',', $position($jump['from_point'])); [$toHorizontal, $toVertical] = explode(',', $position($jump['to_point'])); @endphp
                <line x1="{{ $fromHorizontal }}" y1="{{ $fromVertical }}" x2="{{ $toHorizontal }}" y2="{{ $toVertical }}" stroke="#dc2626" stroke-width="3" stroke-dasharray="3 3" />
            @endforeach
            @foreach ($mappedStops as $stop)
                @if (is_array($stop['point']))
                    @php [$horizontal, $vertical] = explode(',', $position($stop['point'])); @endphp
                    <circle cx="{{ $horizontal }}" cy="{{ $vertical }}" r="5" fill="#4b5563" stroke="white" stroke-width="1" />
                    <text x="{{ $horizontal + 8 }}" y="{{ $vertical - 6 }}" font-size="10" fill="currentColor">{{ $stop['sequence'] }}. {{ $stop['name'] }}</text>
                @endif
            @endforeach
            @foreach ($trace as $point)
                @if (is_array($point['point']))
                    @php [$horizontal, $vertical] = explode(',', $position($point['point'])); @endphp
                    <circle cx="{{ $horizontal }}" cy="{{ $vertical }}" r="4" fill="#2563eb" />
                @endif
            @endforeach
            @foreach ($observed as $observation)
                @if (is_array($observation['point']))
                    @php [$horizontal, $vertical] = explode(',', $position($observation['point'])); @endphp
                    <circle cx="{{ $horizontal }}" cy="{{ $vertical }}" r="7" fill="#d97706" stroke="white" stroke-width="2" />
                    <text x="{{ $horizontal + 9 }}" y="{{ $vertical - 8 }}" font-size="11" fill="currentColor">Obs {{ $observation['sequence'] }}: {{ $observation['type'] }}</text>
                @endif
            @endforeach
        </svg>
        <p class="text-xs text-gray-500 dark:text-gray-400">Gray: current mapped route and stops · Blue: optional ordered trace · Amber: field observations · Red: possible trace jumps for reviewer attention. Accuracy and precision categories remain collector-supplied.</p>
    @else
        <p class="text-sm text-gray-500">No recorded coordinates. A survey is valid without GPS.</p>
    @endif
    @if (count($jumps) > 0)
        <ul class="list-disc pl-5 text-sm text-red-700 dark:text-red-300">
            @foreach ($jumps as $jump)
                <li>Review trace samples {{ $jump['from_sequence'] }}–{{ $jump['to_sequence'] }}: {{ number_format($jump['distance_m']) }} m apart{{ $jump['elapsed_seconds'] === null ? '' : ' in '.$jump['elapsed_seconds'].' s' }}.</li>
            @endforeach
        </ul>
    @endif
    @if (count($mappedStops) > 0)
        <p class="text-xs text-gray-500 dark:text-gray-400">Mapped stops without coordinates: {{ collect($mappedStops)->filter(fn ($stop) => $stop['point'] === null)->pluck('name')->implode(', ') ?: 'none' }}.</p>
    @endif
</div>
