<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationCommercialAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->hasAnyRole(['admin', 'operator', 'technician'])) {
            return $next($request);
        }
        $subscription = $user->organization?->commercialSubscription()->first();
        if (! $subscription?->blocksOperations() || $this->allowedDuringSuspension($request)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => $user->hasRole('admin')
                ? 'Operational access is suspended. Review the organization subscription to restore service.'
                : 'Operational access is suspended. Contact your organization administrator.',
            'code' => 'organization_subscription_required',
            'billing_url' => $user->hasRole('admin') ? '/organization-billing' : '/subscription-required',
        ], 402);
    }

    private function allowedDuringSuspension(Request $request): bool
    {
        return $request->is(
            'api/profile*',
            'api/account-preferences*',
            'api/account-security*',
            'api/notification-preferences*',
            'api/notifications*',
            'api/organization-billing*',
            'api/commercial/plans',
            'api/commercial/invoices/*/document',
        );
    }
}
