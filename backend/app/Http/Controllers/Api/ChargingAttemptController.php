<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\StartChargingAttemptRequest;
use App\Http\Resources\ChargingAttemptResource;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\User;
use App\Services\ChargingAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChargingAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attempts = ChargingAttempt::query()
            ->where('user_id', $user->id)
            ->with(['organization', 'station', 'connector', 'vehicle', 'chargingSession', 'commands' => fn ($query) => $query->latest('id')])
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json(['data' => ChargingAttemptResource::collection($attempts)]);
    }

    public function store(StartChargingAttemptRequest $request, ChargingAttemptService $service): JsonResponse
    {
        Gate::authorize('create', ChargingSession::class);
        /** @var User $user */
        $user = $request->user();
        $attempt = $service->start($user, $request->validated());

        return (new ChargingAttemptResource($attempt))->response()->setStatusCode(201);
    }

    public function show(Request $request, ChargingAttempt $chargingAttempt, ChargingAttemptService $service): ChargingAttemptResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $chargingAttempt->user_id === $user->id
                || $user->hasRole('super_admin')
                || (! $user->hasRole('client') && $user->canAccessOrganization($chargingAttempt->organization_id)),
            403,
        );

        return new ChargingAttemptResource($service->load($chargingAttempt));
    }
}
