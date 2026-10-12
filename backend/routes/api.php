<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\ClientAccountController;
use App\Http\Controllers\Api\V1\Admin\DepotController;
use App\Http\Controllers\Api\V1\Admin\RegionAssignmentController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Adv\DayController;
use App\Http\Controllers\Api\V1\Adv\QuotaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientCategoryController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductFamilyController;
use App\Http\Controllers\Api\V1\ProductMediaController;
use App\Http\Controllers\Api\V1\ProductPriceController;
use App\Http\Controllers\Api\V1\Regional\DistributionGridController;
use App\Http\Controllers\Api\V1\Regional\LimitController;
use App\Http\Controllers\Api\V1\RegionalQuotaController;
use App\Http\Controllers\Api\V1\RegionController;
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
            // Référentiels (lot 2) — contrat : docs/lot-2-contrat-api.md
            Route::get('regions', [RegionController::class, 'index'])->name('regions.index');
            Route::get('regions/{region}', [RegionController::class, 'show'])->name('regions.show');

            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::patch('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            Route::get('client-categories', [ClientCategoryController::class, 'index'])->name('client-categories.index');

            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
            Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::get('products/{product}/prices', [ProductPriceController::class, 'index'])->name('products.prices.index');
            Route::post('products/{product}/prices', [ProductPriceController::class, 'store'])->name('products.prices.store');
            Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->name('products.media.store');
            Route::scopeBindings()->group(function () {
                Route::patch('products/{product}/media/{media}', [ProductMediaController::class, 'update'])->name('products.media.update');
                Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');
            });
            Route::get('product-families', [ProductFamilyController::class, 'index'])->name('product-families.index');

            Route::get('imports/templates/{type}', [ImportController::class, 'template'])
                ->whereIn('type', ['clients', 'products', 'prices', 'quotas'])
                ->name('imports.template');
            Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
            Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
            Route::get('imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('imports.show');
            Route::post('imports/{import}/apply', [ImportController::class, 'apply'])->whereNumber('import')->name('imports.apply');
            Route::get('imports/{import}/error-report', [ImportController::class, 'errorReport'])
                ->whereNumber('import')
                ->name('imports.error-report');

            // Journées et quotas (lot 3) — contrat : docs/lot-3-contrat-api.md
            // {businessDay} : journée « AAAA-MM-JJ » (liaison dans AppServiceProvider).
            Route::prefix('adv')->name('adv.')->group(function () {
                Route::get('days', [DayController::class, 'index'])->name('days.index');
                Route::post('days', [DayController::class, 'store'])->name('days.store');
                Route::get('days/{businessDay}', [DayController::class, 'show'])->name('days.show');
                Route::patch('days/{businessDay}', [DayController::class, 'update'])->name('days.update');
                Route::post('days/{businessDay}/regions/{region}/extend-cutoff', [DayController::class, 'extendCutoff'])
                    ->name('days.extend-cutoff');
                Route::get('days/{businessDay}/quotas', [QuotaController::class, 'index'])->name('quotas.index');
                Route::put('days/{businessDay}/quotas', [QuotaController::class, 'update'])->name('quotas.update');
                Route::post('days/{businessDay}/quotas/publish', [QuotaController::class, 'publish'])->name('quotas.publish');
                Route::post('days/{businessDay}/quotas/copy', [QuotaController::class, 'copy'])->name('quotas.copy');
            });
            Route::get('regional/quotas', [RegionalQuotaController::class, 'index'])->name('regional.quotas.index');

            // Répartition (lot 4) — contrat : docs/lot-4-contrat-api.md
            Route::prefix('regional/days/{businessDay}')->name('regional.')->group(function () {
                Route::get('grid', [DistributionGridController::class, 'show'])->name('grid.show');
                Route::put('limits', [LimitController::class, 'update'])->name('limits.update');
                Route::post('limits/copy-previous', [LimitController::class, 'copyPrevious'])->name('limits.copy-previous');
                Route::post('distribution/publish', [LimitController::class, 'publish'])->name('distribution.publish');
            });
            // Répartition en lecture pour l'ADV et l'Admin.
            Route::get('adv/days/{businessDay}/regions/{region}/grid', [DistributionGridController::class, 'showForBackOffice'])
                ->name('adv.grid.show');

            // Centre de notifications (EF-NOT-01).
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
                ->whereUuid('notification')
                ->name('notifications.read');

            Route::prefix('admin')->name('admin.')->group(function () {
                Route::get('depots', [DepotController::class, 'index'])->name('depots.index');
                Route::post('depots', [DepotController::class, 'store'])->name('depots.store');
                Route::patch('depots/{depot}', [DepotController::class, 'update'])->name('depots.update');
                Route::get('regions', [RegionController::class, 'index'])->name('regions.index');
                Route::post('regions', [RegionController::class, 'store'])->name('regions.store');
                Route::patch('regions/{region}', [RegionController::class, 'update'])->name('regions.update');
                Route::get('regions/{region}/assignments', [RegionAssignmentController::class, 'index'])->name('regions.assignments.index');
                Route::post('regions/{region}/assignments', [RegionAssignmentController::class, 'store'])->name('regions.assignments.store');
                Route::delete('assignments/{assignment}', [RegionAssignmentController::class, 'destroy'])->name('assignments.destroy');

                Route::post('client-categories', [ClientCategoryController::class, 'store'])->name('client-categories.store');
                Route::patch('client-categories/{category}', [ClientCategoryController::class, 'update'])->name('client-categories.update');
                Route::post('client-accounts', [ClientAccountController::class, 'store'])->name('client-accounts.store');

                Route::post('product-families', [ProductFamilyController::class, 'store'])->name('product-families.store');
                Route::patch('product-families/{family}', [ProductFamilyController::class, 'update'])->name('product-families.update');

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
