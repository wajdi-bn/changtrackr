<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => (new UserResource($this->resource))->resolve($request),
            'personal' => [
                'name' => $this->name,
                'phone' => $this->phone,
                'job_title' => $this->job_title,
                'bio' => $this->bio,
            ],
            'address' => [
                'address_line_1' => $this->address_line_1 ?? $this->address,
                'address_line_2' => $this->address_line_2,
                'city' => $this->city,
                'region' => $this->region,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
            ],
            'professional_links' => [
                'linkedin_url' => $this->linkedin_url,
                'website_url' => $this->website_url,
            ],
            'metadata' => [
                'account_created_at' => $this->created_at?->toISOString(),
                'profile_updated_at' => $this->updated_at?->toISOString(),
                'last_login_at' => $this->last_login_at?->toISOString(),
                'email_verified_at' => $this->email_verified_at?->toISOString(),
                'sign_in_providers' => $this->whenLoaded('socialAccounts', fn () => $this->socialAccounts
                    ->pluck('provider')
                    ->unique()
                    ->values()),
                'local_password_configured' => $this->hasLocalPasswordLogin(),
            ],
        ];
    }
}
