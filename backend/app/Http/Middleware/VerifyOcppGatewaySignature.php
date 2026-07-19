<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyOcppGatewaySignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('ocpp.gateway.shared_secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            return new JsonResponse(['message' => 'OCPP gateway authentication is not configured.'], 503);
        }

        $timestamp = $request->header('X-ChargeTrackr-Timestamp');
        $requestId = $request->header('X-ChargeTrackr-Request-Id');
        $providedSignature = $request->header('X-ChargeTrackr-Signature');
        $tolerance = max(30, (int) config('ocpp.gateway.signature_tolerance_seconds', 300));

        if (! ctype_digit((string) $timestamp)
            || ! is_string($requestId)
            || ! Str::isUuid($requestId)
            || ! is_string($providedSignature)
            || abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return $this->unauthorized();
        }

        $canonical = $timestamp.'.'.$requestId.'.'.$request->getContent();
        $expectedSignature = 'v1='.hash_hmac('sha256', $canonical, $secret);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return $this->unauthorized();
        }

        $replayKey = 'ocpp-gateway-request:'.hash('sha256', $requestId);
        if (! Cache::add($replayKey, true, now()->addSeconds($tolerance))) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['message' => 'Invalid OCPP gateway signature.'], 401);
    }
}
