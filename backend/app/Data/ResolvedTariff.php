<?php

namespace App\Data;

final readonly class ResolvedTariff
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $source,
        public string $currency,
        public int $pricePerKwhMillimes,
        public int $sessionFeeMillimes,
        public int $idleFeePerMinuteMillimes,
        public int $minimumChargeMillimes,
    ) {}
}
