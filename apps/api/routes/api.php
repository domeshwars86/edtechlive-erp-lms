<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredOrganizationController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'edtechlive-api',
        'version' => 'v1',
    ]))->name('api.v1.health');

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [RegisteredOrganizationController::class, 'store'])
            ->middleware('throttle:api-auth-register')->name('api.v1.auth.register');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:api-auth-login')->name('api.v1.auth.login');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:api-auth-verification')->name('api.v1.auth.verification.send');
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:api-auth-password-reset')->name('api.v1.auth.password.email');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:api-auth-password-reset')->name('api.v1.auth.password.update');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])->name('api.v1.auth.logout');

        Route::middleware('verified')->group(function (): void {
            Route::get('/organizations', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
                ->whereNumber('organization')->name('api.v1.organizations.show');
        });
    });
});
