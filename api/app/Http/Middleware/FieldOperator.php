<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FieldOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->is_field_operator) {
            $request->attributes->set('field_operator', $user);

            return $next($request);
        }

        $expected = (string) config('biyahe.field.operator_token', '');
        $configuredUserId = (int) config('biyahe.field.operator_user_id', 0);
        $provided = (string) ($request->bearerToken() ?: $request->header('X-Biyahe-Field-Token', ''));
        if ($expected !== '' && $configuredUserId > 0 && $provided !== '' && hash_equals($expected, $provided)) {
            $operator = User::query()->find($configuredUserId);
            if ($operator instanceof User && $operator->is_field_operator) {
                $request->attributes->set('field_operator', $operator);

                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Field verification requires an authorized field operator.',
            'error' => ['code' => 'FIELD_OPERATOR_REQUIRED', 'message' => 'Sign in with an authorized field-operator account.'],
        ], $user instanceof User ? 403 : 401);
    }
}
