<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\StoreVehicleRequest;
use App\Http\Requests\Vehicles\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->client($request);

        return response()->json([
            'data' => VehicleResource::collection($user->vehicles()
                ->withCount('chargingSessions')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()),
        ]);
    }

    public function store(StoreVehicleRequest $request, PlatformAuditService $audit): JsonResponse
    {
        $user = $this->client($request);
        $vehicle = DB::transaction(function () use ($request, $user): Vehicle {
            $attributes = $request->validated();
            $isDefault = ($attributes['is_default'] ?? false) || ! $user->vehicles()->exists();
            if ($isDefault) {
                $user->vehicles()->update(['is_default' => false]);
            }

            return $user->vehicles()->create([...$attributes, 'is_default' => $isDefault]);
        });
        $audit->record($user, 'vehicle.created', $vehicle, 'Created a vehicle profile.', ['vehicle_id' => $vehicle->id]);

        return (new VehicleResource($vehicle))->response()->setStatusCode(201);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle, PlatformAuditService $audit): VehicleResource
    {
        $user = $this->client($request);
        $this->assertOwner($vehicle, $user);
        $attributes = $request->validated();
        DB::transaction(function () use ($attributes, $user, $vehicle): void {
            if (($attributes['is_default'] ?? false) === true) {
                $user->vehicles()->whereKeyNot($vehicle->id)->update(['is_default' => false]);
            }
            $vehicle->update($attributes);
        });
        $audit->record($user, 'vehicle.updated', $vehicle, 'Updated a vehicle profile.', ['vehicle_id' => $vehicle->id]);

        return new VehicleResource($vehicle->fresh());
    }

    public function destroy(Request $request, Vehicle $vehicle, PlatformAuditService $audit): JsonResponse
    {
        $user = $this->client($request);
        $this->assertOwner($vehicle, $user);
        $wasDefault = $vehicle->is_default;
        $vehicle->delete();
        if ($wasDefault) {
            $user->vehicles()->orderBy('id')->first()?->update(['is_default' => true]);
        }
        $audit->record($user, 'vehicle.deleted', $vehicle, 'Deleted a vehicle profile.', ['vehicle_id' => $vehicle->id]);

        return response()->json(status: 204);
    }

    private function client(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole('client') && $user->can('vehicles.manage'), 403);

        return $user;
    }

    private function assertOwner(Vehicle $vehicle, User $user): void
    {
        abort_unless($vehicle->user_id === $user->id, 403);
    }
}
