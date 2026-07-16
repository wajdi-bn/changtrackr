<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Middleware\EnsureUserOrganizationScope;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/auth/login', [AuthController::class, 'login'])
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
