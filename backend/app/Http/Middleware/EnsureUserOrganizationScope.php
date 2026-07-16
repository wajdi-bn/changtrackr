<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserOrganizationScope
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            return new JsonResponse(['message' => 'This account is not active.'], 403);
        }

        if (! $user->hasValidOrganizationAssignment()) {
            return new JsonResponse([
                'message' => 'This account does not have a valid organization assignment.',
            ], 403);
        }

        return $next($request);
    }
}
