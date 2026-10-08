<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 (§11). Authentification par cookie de session Sanctum (SPA) :
| GET /sanctum/csrf-cookie puis POST /api/v1/auth/login.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnforceSessionPolicy::class, 'throttle:api'])->group(function () {
        // Accessibles même lorsque le changement de mot de passe est exigé.
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::middleware(EnsurePasswordIsChanged::class)->group(function () {
            Route::prefix('admin')->name('admin.')->group(function () {
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
                Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
                    ->name('users.reset-password');

                Route::get('settings', [SettingsController::class, 'show'])
                    ->middleware('can:settings.manage')
                    ->name('settings.show');
                Route::put('settings', [SettingsController::class, 'update'])
                    ->middleware('can:settings.manage')
                    ->name('settings.update');

                Route::get('audit-logs', [AuditLogController::class, 'index'])
                    ->middleware('can:audit.view')
                    ->name('audit-logs.index');
            });
        });
    });
});
