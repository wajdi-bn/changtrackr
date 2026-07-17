<?php

use App\Http\Controllers\Api\AccountInvitationController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\ChargingPlanController;
use App\Http\Controllers\Api\ChargingSessionController;
use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DemoRequestController;
use App\Http\Controllers\Api\InterventionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlanSubscriptionController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TariffAssignmentController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureUserOrganizationScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/demo-requests', [DemoRequestController::class, 'store'])
    ->middleware('throttle:demo-request-submit');
Route::post('/account-invitations/inspect', [AccountInvitationController::class, 'inspect'])
    ->middleware('throttle:invitation-inspect');
Route::post('/account-invitations/accept', [AccountInvitationController::class, 'accept'])
    ->middleware('throttle:invitation-accept');

Route::middleware(['auth:sanctum', EnsureUserOrganizationScope::class])->group(function (): void {
    Route::get('/demo-requests', [DemoRequestController::class, 'index']);
    Route::get('/demo-requests/{demoRequest}', [DemoRequestController::class, 'show']);
    Route::patch('/demo-requests/{demoRequest}', [DemoRequestController::class, 'update']);
    Route::post('/demo-requests/{demoRequest}/start-review', [DemoRequestController::class, 'startReview']);
    Route::post('/demo-requests/{demoRequest}/reject', [DemoRequestController::class, 'reject']);
    Route::post('/demo-requests/{demoRequest}/reopen', [DemoRequestController::class, 'reopen']);
    Route::post('/demo-requests/{demoRequest}/provision', [DemoRequestController::class, 'provision']);
    Route::post('/demo-requests/{demoRequest}/invitation/issue', [DemoRequestController::class, 'issueInvitation']);
    Route::post('/demo-requests/{demoRequest}/invitation/revoke', [DemoRequestController::class, 'revokeInvitation']);

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

    Route::get('/subscription-plans', [PlanSubscriptionController::class, 'catalog']);
    Route::get('/subscriptions', [PlanSubscriptionController::class, 'index']);
    Route::post('/subscriptions', [PlanSubscriptionController::class, 'store']);
    Route::patch('/subscriptions/{subscription}', [PlanSubscriptionController::class, 'update']);
    Route::delete('/subscriptions/{subscription}', [PlanSubscriptionController::class, 'destroy']);

    Route::get('/users/export', [UserController::class, 'export']);
    Route::apiResource('users', UserController::class);
    Route::get('/customers/export', [CustomerController::class, 'export']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);

    Route::apiResource('tariffs', TariffController::class);
    Route::apiResource('charging-plans', ChargingPlanController::class);
    Route::post('/tariffs/{tariff}/assignments', [TariffAssignmentController::class, 'store']);
    Route::delete('/tariff-assignments/{tariffAssignment}', [TariffAssignmentController::class, 'destroy']);
    Route::get('/stations/{station}/pricing', [PricingController::class, 'effective']);
    Route::post('/pricing/simulate', [PricingController::class, 'simulate']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', EnsureUserOrganizationScope::class]);
