<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingSessionController;
use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\InterventionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TariffAssignmentController;
use App\Http\Controllers\Api\TariffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('stations', StationController::class);
    Route::post('/stations/{station}/connectors', [ConnectorController::class, 'store']);
    Route::put('/stations/{station}/connectors/{connector}', [ConnectorController::class, 'update']);
    Route::delete('/stations/{station}/connectors/{connector}', [ConnectorController::class, 'destroy']);

    Route::apiResource('alerts', AlertController::class)->except('destroy');
    Route::post('/alerts/{alert}/interventions', [InterventionController::class, 'store']);
    Route::get('/interventions', [InterventionController::class, 'index']);
    Route::get('/interventions/{intervention}', [InterventionController::class, 'show']);
    Route::patch('/interventions/{intervention}', [InterventionController::class, 'update']);
    Route::post('/interventions/{intervention}/notes', [InterventionController::class, 'addNote']);

    Route::get('/charging-sessions', [ChargingSessionController::class, 'index']);
    Route::post('/charging-sessions', [ChargingSessionController::class, 'store']);
    Route::get('/charging-sessions/{chargingSession}', [ChargingSessionController::class, 'show']);
    Route::post('/charging-sessions/{chargingSession}/stop', [ChargingSessionController::class, 'stop']);
    Route::post('/charging-sessions/{chargingSession}/payments', [PaymentController::class, 'store']);
    Route::get('/payments', [PaymentController::class, 'index']);

    Route::apiResource('tariffs', TariffController::class);
    Route::post('/tariffs/{tariff}/assignments', [TariffAssignmentController::class, 'store']);
    Route::delete('/tariff-assignments/{tariffAssignment}', [TariffAssignmentController::class, 'destroy']);
    Route::get('/stations/{station}/pricing', [PricingController::class, 'effective']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
