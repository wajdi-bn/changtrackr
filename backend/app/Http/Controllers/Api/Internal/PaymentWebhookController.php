<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\PaymentProviderWebhookRequest;
use App\Services\Payments\PaymentProviderEventService;
use Illuminate\Http\JsonResponse;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        PaymentProviderWebhookRequest $request,
        PaymentProviderEventService $events,
    ): JsonResponse {
        $result = $events->ingest(
            $request->validated(),
            $request->header('X-ChargeTrackr-Signature'),
        );

        return response()->json([
            'event_id' => $result['event']->event_id,
            'processing_status' => $result['event']->processing_status,
            'duplicate' => $result['duplicate'],
        ], $result['duplicate'] ? 200 : 202);
    }
}
