<?php

namespace App\Data;

use App\Models\OcppIdTag;
use App\Models\User;

final readonly class OcppAuthorizationResult
{
    public function __construct(
        public string $status,
        public ?OcppIdTag $idTag = null,
        public ?User $user = null,
    ) {}

    public function accepted(): bool
    {
        return $this->status === 'Accepted';
    }
}
