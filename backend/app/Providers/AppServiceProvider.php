<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('demo-request-submit', fn (Request $request) => Limit::perHour(3)
            ->by('demo-submit:'.$request->ip()));
        RateLimiter::for('invitation-inspect', fn (Request $request) => Limit::perMinute(10)
            ->by('invitation-inspect:'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(5)
            ->by('invitation-accept:'.$request->ip().':'.sha1(mb_strtolower((string) $request->input('email')))));
        RateLimiter::for('employee-invitation-management', fn (Request $request) => Limit::perMinute(10)
            ->by('employee-invitation-management:'.$request->user()?->id));
        RateLimiter::for('ocpp-gateway', fn (Request $request) => Limit::perMinute(600)
            ->by('ocpp-gateway:'.$request->ip()));
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
}
