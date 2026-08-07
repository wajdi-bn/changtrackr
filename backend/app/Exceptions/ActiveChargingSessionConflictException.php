<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ActiveChargingSessionConflictException extends RuntimeException
{
    public const MESSAGE = 'An active charging session already exists for this client.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
            'errors' => [
                'session' => [self::MESSAGE],
            ],
        ], 422);
    }
}
