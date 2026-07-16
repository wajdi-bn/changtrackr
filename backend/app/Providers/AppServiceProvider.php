<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('frontend.url'), '/');

            return $frontendUrl.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }
}
