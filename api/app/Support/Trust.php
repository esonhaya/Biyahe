<?php

namespace App\Support;

use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class Trust
{
    public static function apply(Builder $query, Request $request, string $table): Builder
    {
        if (! $request->boolean('include_demo')) {
            $query->where("{$table}.is_demo", false);
        }

        if (! $request->boolean('include_retired')) {
            $query->where("{$table}.verification_state", '!=', VerificationState::RETIRED->value);
        }

        if ($request->boolean('trusted_only')) {
            $query->where("{$table}.is_demo", false)
                ->where("{$table}.verification_state", VerificationState::VERIFIED->value);
        } elseif ($request->filled('verification_state')) {
            $state = strtoupper((string) $request->query('verification_state'));
            if (in_array($state, VerificationState::values(), true)) {
                $query->where("{$table}.verification_state", $state);
            }
        }

        return $query;
    }

    /**
     * @return array{state: string|null, is_demo: bool, trusted: bool}
     */
    public static function status(Model $model): array
    {
        $state = $model->verification_state instanceof VerificationState
            ? $model->verification_state->value
            : $model->verification_state;
        $isDemo = (bool) $model->is_demo;

        return [
            'state' => $state,
            'is_demo' => $isDemo,
            'trusted' => $state === VerificationState::VERIFIED->value && ! $isDemo,
        ];
    }

    public static function isRoutable(Model $model): bool
    {
        $state = $model->verification_state instanceof VerificationState
            ? $model->verification_state->value
            : $model->verification_state;

        return (bool) ($model->is_active ?? true)
            && ! (bool) ($model->is_demo ?? false)
            && in_array($state, [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value], true);
    }

    public static function applyRoutable(Builder $query, string $table): Builder
    {
        return $query
            ->where("{$table}.is_active", true)
            ->where("{$table}.is_demo", false)
            ->whereIn("{$table}.verification_state", [
                VerificationState::VERIFIED->value,
                VerificationState::UNVERIFIED->value,
            ]);
    }
}
