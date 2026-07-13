<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\ChargingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewCustomers', User::class);
        $filters = $this->validateFilters($request);
        /** @var User $actor */
        $actor = $request->user();
        $customers = $this->applyFilters($this->customerQuery($actor), $actor, $filters);
        $this->applySorting($customers, $filters['sort'] ?? 'latest');
        $customers = $customers
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'summary' => $this->summary($actor),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function show(Request $request, User $customer): CustomerResource
    {
        Gate::authorize('viewCustomer', $customer);
        /** @var User $actor */
        $actor = $request->user();
        $customer = $this->customerQuery($actor)->findOrFail($customer->id);
        $customer->load(['chargingSessions' => function (Relation $query) use ($actor): void {
            $this->scopeSessions($query, $actor);
            $query->with(['organization', 'station', 'connector', 'payment'])
                ->orderByDesc('started_at')
                ->limit(5);
        }]);

        return new CustomerResource($customer);
    }

    public function export(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('exportCustomers', User::class);
        $filters = $this->validateFilters($request);
        $format = $request->validate([
            'format' => ['required', Rule::in(['csv', 'json'])],
        ])['format'];
        /** @var User $actor */
        $actor = $request->user();
        $customers = $this->applyFilters($this->customerQuery($actor), $actor, $filters);
        $this->applySorting($customers, $filters['sort'] ?? 'latest');
        $rows = $customers->get()->map(fn (User $customer) => $this->exportRow($customer));

        if ($format === 'json') {
            return response()->json(['data' => $rows]);
        }

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['Name', 'Email', 'Phone', 'Status', 'Sessions', 'Stations', 'Energy (kWh)', 'Paid (millimes)', 'Outstanding (millimes)', 'Last session']);
            foreach ($rows as $row) {
                fputcsv($output, array_values($row));
            }
            fclose($output);
        }, 'organization-customers.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'pending'])],
            'last_activity' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'sort' => ['nullable', Rule::in(['latest', 'name', 'sessions', 'energy', 'spent'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function customerQuery(User $actor): Builder
    {
        $query = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'client'))
            ->whereHas('chargingSessions', fn (Builder $query) => $this->scopeSessions($query, $actor))
            ->withCount(['chargingSessions as customer_sessions_count' => fn (Builder $query) => $this->scopeSessions($query, $actor)])
            ->withSum(['chargingSessions as customer_energy_kwh' => fn (Builder $query) => $this->scopeSessions($query, $actor)], 'energy_kwh')
            ->withSum(['chargingSessions as customer_paid_millimes' => function (Builder $query) use ($actor): void {
                $this->scopeSessions($query, $actor);
                $query->where('payment_status', 'paid');
            }], 'total_millimes')
            ->withSum(['chargingSessions as customer_outstanding_millimes' => function (Builder $query) use ($actor): void {
                $this->scopeSessions($query, $actor);
                $query->where('payment_status', 'unpaid');
            }], 'total_millimes')
            ->withMin(['chargingSessions as customer_first_session_at' => fn (Builder $query) => $this->scopeSessions($query, $actor)], 'started_at')
            ->withMax(['chargingSessions as customer_last_session_at' => fn (Builder $query) => $this->scopeSessions($query, $actor)], 'started_at');

        $stationCount = ChargingSession::query()
            ->selectRaw('COUNT(DISTINCT station_id)')
            ->whereColumn('client_id', 'users.id');
        $this->scopeSessions($stationCount, $actor);

        return $query->addSelect(['customer_stations_count' => $stationCount]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, User $actor, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$needle]));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['last_activity'] ?? null, function (Builder $query, string $period) use ($actor): void {
                $start = match ($period) {
                    'today' => now()->startOfDay(),
                    'week' => now()->subWeek(),
                    'month' => now()->subMonth(),
                };
                $query->whereHas('chargingSessions', function (Builder $query) use ($actor, $start): void {
                    $this->scopeSessions($query, $actor);
                    $query->where('started_at', '>=', $start);
                });
            });
    }

    private function applySorting(Builder $query, string $sort): void
    {
        match ($sort) {
            'name' => $query->orderBy('name'),
            'sessions' => $query->orderByDesc('customer_sessions_count'),
            'energy' => $query->orderByDesc('customer_energy_kwh'),
            'spent' => $query->orderByDesc('customer_paid_millimes'),
            default => $query->orderByDesc('customer_last_session_at'),
        };
    }

    private function scopeSessions(Builder|Relation $query, User $actor): void
    {
        if (! $actor->hasRole('super_admin')) {
            $query->where('organization_id', $actor->organization_id);
        }
    }

    /** @return array<string, int|float> */
    private function summary(User $actor): array
    {
        $sessions = ChargingSession::query()->whereNotNull('client_id');
        $this->scopeSessions($sessions, $actor);

        return [
            'total' => $this->customerQuery($actor)->count(),
            'active_30_days' => (clone $sessions)->where('started_at', '>=', now()->subDays(30))->distinct()->count('client_id'),
            'sessions' => (clone $sessions)->count(),
            'energy_kwh' => round((float) (clone $sessions)->sum('energy_kwh'), 3),
            'revenue_millimes' => (int) (clone $sessions)->where('payment_status', 'paid')->sum('total_millimes'),
        ];
    }

    /** @return array<string, mixed> */
    private function exportRow(User $customer): array
    {
        return [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => $customer->status,
            'sessions' => (int) $customer->customer_sessions_count,
            'stations' => (int) $customer->customer_stations_count,
            'energy_kwh' => round((float) $customer->customer_energy_kwh, 3),
            'paid_millimes' => (int) $customer->customer_paid_millimes,
            'outstanding_millimes' => (int) $customer->customer_outstanding_millimes,
            'last_session_at' => $customer->customer_last_session_at,
        ];
    }
}
