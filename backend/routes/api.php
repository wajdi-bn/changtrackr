<?php

use App\Http\Controllers\Api\AccountInvitationController;
use App\Http\Controllers\Api\AccountPreferenceController;
use App\Http\Controllers\Api\AccountSecurityController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\ChargingAttemptController;
use App\Http\Controllers\Api\ChargingPlanController;
use App\Http\Controllers\Api\ChargingSessionController;
use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\ConnectorQrController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DemoRequestController;
use App\Http\Controllers\Api\EmployeeInvitationController;
use App\Http\Controllers\Api\Internal\OcppGatewayController;
use App\Http\Controllers\Api\Internal\PaymentWebhookController;
use App\Http\Controllers\Api\InterventionController;
use App\Http\Controllers\Api\InterventionReportController;
use App\Http\Controllers\Api\InternalReportController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OperationalDocumentController;
use App\Http\Controllers\Api\OcppSupervisionController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlanSubscriptionController;
use App\Http\Controllers\Api\PlatformAuditLogController;
use App\Http\Controllers\Api\PlatformIntegrationController;
use App\Http\Controllers\Api\PlatformSettingController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\ReportAnalyticsController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TariffAssignmentController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Middleware\EnsureUserOrganizationScope;
use App\Http\Middleware\VerifyOcppGatewaySignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/demo-requests', [DemoRequestController::class, 'store'])
    ->middleware('throttle:demo-request-submit');
Route::post('/account-invitations/inspect', [AccountInvitationController::class, 'inspect'])
    ->middleware('throttle:invitation-inspect');
Route::post('/account-invitations/accept', [AccountInvitationController::class, 'accept'])
    ->middleware('throttle:invitation-accept');
Route::post('/internal/payments/webhooks', PaymentWebhookController::class)
    ->middleware('throttle:payment-webhook');

Route::prefix('/internal/ocpp')
    ->middleware([VerifyOcppGatewaySignature::class, 'throttle:ocpp-gateway'])
    ->group(function (): void {
        Route::post('/authenticate', [OcppGatewayController::class, 'authenticate']);
        Route::post('/events', [OcppGatewayController::class, 'ingest']);
        Route::post('/commands/claim', [OcppGatewayController::class, 'claimCommand']);
        Route::post('/commands/{ocppCommand}/result', [OcppGatewayController::class, 'completeCommand']);
    });

Route::middleware(['auth:sanctum', EnsureUserOrganizationScope::class])->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'storeAvatar']);
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);
    Route::get('/dashboard', DashboardController::class);
    Route::apiResource('organizations', OrganizationController::class)->only(['index', 'show', 'store', 'update']);
    Route::get('/platform/roles-permissions', [RolePermissionController::class, 'index']);
    Route::put('/platform/roles/{roleName}/permissions', [RolePermissionController::class, 'update']);
    Route::get('/platform/audit-logs/export', [PlatformAuditLogController::class, 'export']);
    Route::get('/platform/audit-logs', [PlatformAuditLogController::class, 'index']);
    Route::get('/platform/integrations', PlatformIntegrationController::class);
    Route::get('/platform/system-settings', [PlatformSettingController::class, 'index']);
    Route::put('/platform/system-settings', [PlatformSettingController::class, 'update']);
    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::patch('/notifications/{userNotification}/read', [UserNotificationController::class, 'read']);
    Route::post('/notifications/read-all', [UserNotificationController::class, 'readAll']);
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);
    Route::get('/account-preferences', [AccountPreferenceController::class, 'show']);
    Route::put('/account-preferences', [AccountPreferenceController::class, 'update']);
    Route::get('/account-security', [AccountSecurityController::class, 'show']);
    Route::put('/account-security/password', [AccountSecurityController::class, 'changePassword'])
        ->middleware('throttle:5,1');
    Route::get('/demo-requests', [DemoRequestController::class, 'index']);
    Route::get('/demo-requests/{demoRequest}', [DemoRequestController::class, 'show']);
    Route::patch('/demo-requests/{demoRequest}', [DemoRequestController::class, 'update']);
    Route::post('/demo-requests/{demoRequest}/start-review', [DemoRequestController::class, 'startReview']);
    Route::post('/demo-requests/{demoRequest}/reject', [DemoRequestController::class, 'reject']);
    Route::post('/demo-requests/{demoRequest}/reopen', [DemoRequestController::class, 'reopen']);
    Route::post('/demo-requests/{demoRequest}/provision', [DemoRequestController::class, 'provision']);
    Route::post('/demo-requests/{demoRequest}/invitation/issue', [DemoRequestController::class, 'issueInvitation']);
    Route::post('/demo-requests/{demoRequest}/invitation/revoke', [DemoRequestController::class, 'revokeInvitation']);

    Route::get('/stations/map', [StationController::class, 'map']);
    Route::get('/connector-qr/{token}', [ConnectorQrController::class, 'show']);
    Route::apiResource('stations', StationController::class);
    Route::post('/stations/{station}/connectors', [ConnectorController::class, 'store']);
    Route::put('/stations/{station}/connectors/{connector}', [ConnectorController::class, 'update']);
    Route::delete('/stations/{station}/connectors/{connector}', [ConnectorController::class, 'destroy']);
    Route::get('/stations/{station}/commands', [OcppSupervisionController::class, 'index']);
    Route::post('/stations/{station}/commands/reset', [OcppSupervisionController::class, 'reset']);
    Route::post('/stations/{station}/connectors/{connector}/commands/unlock', [OcppSupervisionController::class, 'unlock']);
    Route::put('/stations/{station}/maintenance', [OcppSupervisionController::class, 'maintenance']);

    Route::apiResource('alerts', AlertController::class)->except('destroy');
    Route::get('/alerts/{alert}/report', [OperationalDocumentController::class, 'alert']);
    Route::post('/alerts/{alert}/interventions', [InterventionController::class, 'store']);
    Route::get('/interventions', [InterventionController::class, 'index']);
    Route::get('/interventions/{intervention}', [InterventionController::class, 'show']);
    Route::patch('/interventions/{intervention}', [InterventionController::class, 'update']);
    Route::post('/interventions/{intervention}/notes', [InterventionController::class, 'addNote']);
    Route::post('/interventions/{intervention}/report', [InterventionReportController::class, 'store']);
    Route::get('/interventions/{intervention}/document', [OperationalDocumentController::class, 'intervention']);
    Route::post('/interventions/{intervention}/photos', [InterventionReportController::class, 'storePhoto']);
    Route::get('/interventions/{intervention}/photos/{photo}', [InterventionReportController::class, 'content']);
    Route::delete('/interventions/{intervention}/photos/{photo}', [InterventionReportController::class, 'destroyPhoto']);
    Route::get('/maintenances', [MaintenanceController::class, 'index']);
    Route::post('/maintenances', [MaintenanceController::class, 'store']);
    Route::patch('/maintenances/{maintenance}', [MaintenanceController::class, 'update']);
    Route::get('/maintenances/{maintenance}/report', [OperationalDocumentController::class, 'maintenance']);

    Route::get('/charging-sessions/export', [ChargingSessionController::class, 'export']);
    Route::get('/charging-sessions', [ChargingSessionController::class, 'index']);
    Route::post('/charging-sessions', [ChargingSessionController::class, 'store']);
    Route::get('/charging-sessions/{chargingSession}', [ChargingSessionController::class, 'show']);
    Route::post('/charging-sessions/{chargingSession}/stop', [ChargingSessionController::class, 'stop']);
    Route::post('/charging-sessions/{chargingSession}/remote-stop', [ChargingSessionController::class, 'remoteStop']);
    Route::get('/charging-attempts', [ChargingAttemptController::class, 'index']);
    Route::post('/charging-attempts', [ChargingAttemptController::class, 'store']);
    Route::get('/charging-attempts/{chargingAttempt}', [ChargingAttemptController::class, 'show']);
    Route::post('/charging-sessions/{chargingSession}/payments', [PaymentController::class, 'store']);
    Route::get('/payments/export', [PaymentController::class, 'export']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}/receipt', [OperationalDocumentController::class, 'receipt']);

    Route::get('/internal-reports/recipients', [InternalReportController::class, 'recipients']);
    Route::get('/internal-reports', [InternalReportController::class, 'index']);
    Route::post('/internal-reports', [InternalReportController::class, 'store']);
    Route::patch('/internal-reports/{internalReport}', [InternalReportController::class, 'update']);
    Route::post('/internal-reports/{internalReport}/send', [InternalReportController::class, 'send']);
    Route::post('/internal-reports/{internalReport}/read', [InternalReportController::class, 'read']);
    Route::post('/internal-reports/{internalReport}/archive', [InternalReportController::class, 'archive']);
    Route::get('/internal-reports/{internalReport}/document', [InternalReportController::class, 'document']);
    Route::delete('/internal-reports/{internalReport}', [InternalReportController::class, 'destroy']);

    Route::get('/reporting/platform', [ReportAnalyticsController::class, 'platform']);
    Route::get('/reporting/organization', [ReportAnalyticsController::class, 'organization']);
    Route::get('/reporting/operations', [ReportAnalyticsController::class, 'operations']);
    Route::get('/reporting/field', [ReportAnalyticsController::class, 'field']);
    Route::get('/reporting/{scope}/export', [ReportAnalyticsController::class, 'export'])
        ->whereIn('scope', ['platform', 'organization', 'operations', 'field']);

    Route::get('/subscription-plans', [PlanSubscriptionController::class, 'catalog']);
    Route::get('/subscriptions', [PlanSubscriptionController::class, 'index']);
    Route::post('/subscriptions', [PlanSubscriptionController::class, 'store']);
    Route::patch('/subscriptions/{subscription}', [PlanSubscriptionController::class, 'update']);
    Route::delete('/subscriptions/{subscription}', [PlanSubscriptionController::class, 'destroy']);

    Route::get('/users/export', [UserController::class, 'export']);
    Route::post('/users/{user}/invitation/remind', [EmployeeInvitationController::class, 'remind'])
        ->middleware('throttle:employee-invitation-management');
    Route::post('/users/{user}/invitation/renew', [EmployeeInvitationController::class, 'renew'])
        ->middleware('throttle:employee-invitation-management');
    Route::delete('/users/{user}/invitation', [EmployeeInvitationController::class, 'cancel'])
        ->middleware('throttle:employee-invitation-management');
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
