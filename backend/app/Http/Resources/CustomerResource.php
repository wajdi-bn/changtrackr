<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'address' => $this->address,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'activity' => [
                'sessions' => (int) ($this->customer_sessions_count ?? 0),
                'stations' => (int) ($this->customer_stations_count ?? 0),
                'energy_kwh' => round((float) ($this->customer_energy_kwh ?? 0), 3),
                'paid_millimes' => (int) ($this->customer_paid_millimes ?? 0),
                'outstanding_millimes' => (int) ($this->customer_outstanding_millimes ?? 0),
                'first_session_at' => $this->serializeDate($this->customer_first_session_at ?? null),
                'last_session_at' => $this->serializeDate($this->customer_last_session_at ?? null),
            ],
            'recent_sessions' => $this->whenLoaded(
                'chargingSessions',
                fn () => ChargingSessionResource::collection($this->chargingSessions),
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function serializeDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }
}
