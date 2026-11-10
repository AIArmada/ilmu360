<?php

declare(strict_types=1);

use App\Features\Identity\EntryPoints\Http\AccountSettingsController;
use App\Features\Identity\EntryPoints\Http\AuthController;
use App\Features\Identity\EntryPoints\Http\CurrentUserController;
use Illuminate\Support\Facades\Route;

// Public authentication endpoints (preserve throttle middleware and names).
Route::middleware('api')->prefix('api/v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:api-auth-register')
        ->name('api.auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-auth-login')
        ->name('api.auth.login');
    Route::post('/auth/social/google', [AuthController::class, 'google'])
        ->middleware('throttle:api-auth-social')
        ->name('api.auth.social.google');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:api-auth-password')
        ->name('api.auth.forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:api-auth-password')
        ->name('api.auth.reset-password');
});

// Authenticated account transport endpoints.
Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
    Route::name('api.client.')->group(function (): void {
        Route::get('/account-settings', [AccountSettingsController::class, 'show'])->name('account-settings.show');
        Route::put('/account-settings', [AccountSettingsController::class, 'update'])->name('account-settings.update');
    });

    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])
        ->name('api.auth.verification-notification');

    Route::get('/user', CurrentUserController::class)->name('api.user.show');
    Route::delete('/user', [CurrentUserController::class, 'destroy'])->name('api.user.destroy');
});
