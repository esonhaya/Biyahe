<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PlaceResource;
use App\Models\Place;
use App\Support\SearchText;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaceSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $queryText = trim((string) $validated['q']);
        $tokens = SearchText::tokens($queryText);
        $tokens = $tokens === [] ? [mb_strtolower($queryText)] : $tokens;
        $normalizedTerm = '%'.SearchText::normalize($queryText).'%';
        $normalizedPlaceName = $this->normalizedSql('places.name');
        $normalizedAlias = $this->normalizedSql('alias');

        $query = Place::query()
            ->with(['city', 'aliases', 'provenanceSource'])
            ->where('places.is_active', true)
            ->when(config('database.default') === 'pgsql', fn (Builder $builder) => $builder->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]))
            ->where(function (Builder $builder) use ($normalizedAlias, $normalizedPlaceName, $normalizedTerm, $tokens): void {
                foreach ($tokens as $token) {
                    $term = '%'.$token.'%';
                    $builder
                        ->orWhereRaw('LOWER(places.name) LIKE ?', [$term])
                        ->orWhereHas('aliases', fn (Builder $alias) => $alias->where('is_active', true)->whereRaw('LOWER(alias) LIKE ?', [$term]));
                }
                $builder
                    ->orWhereRaw($normalizedPlaceName.' LIKE ?', [$normalizedTerm])
                    ->orWhereHas('aliases', fn (Builder $alias) => $alias->where('is_active', true)->whereRaw($normalizedAlias.' LIKE ?', [$normalizedTerm]));
            });
        Trust::apply($query, $request, 'places');
        if (! empty($validated['city'])) {
            $city = (string) $validated['city'];
            $query->whereHas('city', function (Builder $builder) use ($city): void {
                $builder->where('slug', $city);
                if (ctype_digit($city)) {
                    $builder->orWhereKey((int) $city);
                }
            });
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $places = $query
            ->orderBy('places.name')
            ->limit(min(100, max(20, $limit * 4)))
            ->get()
            ->sort(function (Place $left, Place $right) use ($queryText): int {
                $leftScore = SearchText::score($queryText, $left->name, $left->aliases->pluck('alias'));
                $rightScore = SearchText::score($queryText, $right->name, $right->aliases->pluck('alias'));

                return ($leftScore <=> $rightScore)
                    ?: strcasecmp($left->name, $right->name)
                    ?: ($left->getKey() <=> $right->getKey());
            })
            ->take($limit)
            ->values();

        return response()->json(['data' => PlaceResource::collection($places)->resolve($request)]);
    }

    private function normalizedSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(LOWER({$column}), ' ', ''), '.', ''), '-', ''), '/', '')";
    }
}
