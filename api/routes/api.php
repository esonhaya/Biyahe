<?php

use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\PlaceSearchController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\RoutePatternController;
use App\Http\Controllers\Api\StopController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class);
    Route::get('cities', [CityController::class, 'index']);
    Route::get('cities/{city}', [CityController::class, 'show']);

    Route::get('places/search', PlaceSearchController::class);
    Route::get('places', [PlaceController::class, 'index']);
    Route::get('places/{place}', [PlaceController::class, 'show']);

    Route::get('journeys', JourneyController::class);

    Route::get('stops/nearby', [StopController::class, 'nearby']);
    Route::get('stops', [StopController::class, 'index']);
    Route::get('stops/{stop}', [StopController::class, 'show']);

    Route::get('routes', [RouteController::class, 'index']);
    Route::get('routes/{route}', [RouteController::class, 'show']);
    Route::get('route-patterns/{routePattern}', [RoutePatternController::class, 'show']);
});
