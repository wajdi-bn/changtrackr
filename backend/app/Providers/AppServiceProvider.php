<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Models\OcppCommand;
use App\Models\User;
use App\OpenApi\NormalizeOperationMetadata;
use App\Services\PlatformSettingService;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PlatformSettingService::class);

        $this->app->bind(PaymentGateway::class, function ($app): PaymentGateway {
            $driver = config('payments.default', 'simulated');
            $adapterClass = config("payments.drivers.{$driver}");

            if (! is_string($adapterClass)) {
                throw new InvalidArgumentException("Unsupported payment driver [{$driver}].");
            }

            $adapter = $app->make($adapterClass);
            if (! $adapter instanceof PaymentGateway) {
                throw new InvalidArgumentException("Payment driver [{$driver}] must implement PaymentGateway.");
            }

            return $adapter;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::configure()
            ->withOperationTransformers(NormalizeOperationMetadata::class);

        Gate::define('viewApiDocs', fn (?User $user = null): bool => app()->environment('local')
            || ($user?->hasRole('super_admin') ?? false));

        RateLimiter::for('demo-request-submit', fn (Request $request) => Limit::perHour(3)
            ->by('demo-submit:'.$request->ip()));
        RateLimiter::for('invitation-inspect', fn (Request $request) => Limit::perMinute(10)
            ->by('invitation-inspect:'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(5)
            ->by('invitation-accept:'.$request->ip().':'.sha1(mb_strtolower((string) $request->input('email')))));
        RateLimiter::for('employee-invitation-management', fn (Request $request) => Limit::perMinute(10)
            ->by('employee-invitation-management:'.$request->user()?->id));
        RateLimiter::for('ocpp-authenticate', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('ocpp.gateway.rate_limits.authenticate_per_minute', 30)),
        )->by($this->ocppRateLimitKey($request, 'authenticate')));
        RateLimiter::for('ocpp-events', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('ocpp.gateway.rate_limits.events_per_minute', 1200)),
        )->by($this->ocppRateLimitKey($request, 'events')));
        RateLimiter::for('ocpp-command-poll', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('ocpp.gateway.rate_limits.command_poll_per_minute', 180)),
        )->by($this->ocppRateLimitKey($request, 'command-poll')));
        RateLimiter::for('ocpp-command-result', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('ocpp.gateway.rate_limits.command_result_per_minute', 120)),
        )->by($this->ocppRateLimitKey($request, 'command-result')));
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)
            ->by('payment-webhook:'.$request->ip()));

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('frontend.url'), '/');

            return $frontendUrl.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }

    private function ocppRateLimitKey(Request $request, string $scope): string
    {
        $stationIdentity = trim((string) $request->input('station_identity'));
        if ($stationIdentity !== '') {
            return "ocpp:{$scope}:station:".hash('sha256', mb_strtolower($stationIdentity));
        }

        $command = $request->route('ocppCommand');
        $commandIdentity = $command instanceof OcppCommand
            ? 'station-id:'.$command->station_id
            : 'command:'.hash('sha256', (string) $command);

        return "ocpp:{$scope}:{$commandIdentity}";
    }
}
