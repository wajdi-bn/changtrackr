<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $service): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d'])],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $service->forUser($user, $filters['period'] ?? '30d'),
        ]);
    }
}
