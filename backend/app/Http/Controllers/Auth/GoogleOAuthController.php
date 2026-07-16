<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\GoogleOAuthException;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, GoogleAccountService $accounts): RedirectResponse
    {
        try {
            $user = $accounts->resolve(Socialite::driver('google')->user());

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $user->forceFill(['last_login_at' => now()])->save();

            return $this->frontendRedirect('/auth/google/callback');
        } catch (GoogleOAuthException $exception) {
            return $this->frontendRedirect('/login?oauth_error='.$exception->errorCode);
        } catch (Throwable $exception) {
            Log::warning('Google OAuth callback failed.', [
                'exception' => $exception::class,
            ]);

            return $this->frontendRedirect('/login?oauth_error=provider_error');
        }
    }

    private function frontendRedirect(string $path): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('frontend.url'), '/');

        return redirect()->away($frontendUrl.$path);
    }
}
