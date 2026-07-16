<?php

use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RegisteredClientController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Middleware\EnsureUserOrganizationScope;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::post('/api/auth/register', [RegisteredClientController::class, 'store'])
    ->middleware('throttle:5,1');

Route::post('/api/auth/email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:3,1');

Route::get('/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('verification.verify');

Route::post('/api/auth/forgot-password', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:5,1');

Route::post('/api/auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1');

Route::get('/api/auth/session', [AuthController::class, 'session'])
    ->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->prefix('api/auth')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me'])
        ->middleware(EnsureUserOrganizationScope::class);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::prefix('auth/oauth/google')->middleware('throttle:20,1')->group(function (): void {
    Route::get('/redirect', [GoogleOAuthController::class, 'redirect'])
        ->name('oauth.google.redirect');
    Route::get('/callback', [GoogleOAuthController::class, 'callback'])
        ->name('oauth.google.callback');
});
