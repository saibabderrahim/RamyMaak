<?php

namespace App\Providers;

use App\Domain\Limits\ClientUsage;
use App\Domain\Limits\NoClientUsage;
use App\Domain\Settings\Settings;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientLimit;
use App\Models\Depot;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Réinitialisé entre deux requêtes ou deux tâches de fond : un worker
        // ne garde pas de paramètres périmés.
        $this->app->scoped(Settings::class);
        // Consommation des clients (S, V) : fournie par les commandes à partir du lot 5.
        $this->app->bind(ClientUsage::class, NoClientUsage::class);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Types polymorphes stables (journal d'audit, rôles) : la base ne
        // dépend pas des noms de classes PHP.
        Relation::enforceMorphMap([
            'user' => User::class,
            'setting' => Setting::class,
            'audit_log' => AuditLog::class,
            'depot' => Depot::class,
            'region' => Region::class,
            'region_assignment' => RegionAssignment::class,
            'client_category' => ClientCategory::class,
            'client' => Client::class,
            'product_family' => ProductFamily::class,
            'product' => Product::class,
            'product_price' => ProductPrice::class,
            'import' => Import::class,
            'business_day' => BusinessDay::class,
            'region_day' => RegionDay::class,
            'region_quota' => RegionQuota::class,
            'client_limit' => ClientLimit::class,
        ]);

        // Journée désignée par sa date « AAAA-MM-JJ » dans les URL (§11.1) ;
        // inconnue ou mal formée : 404.
        Route::pattern('businessDay', '\d{4}-\d{2}-\d{2}');
        Route::bind('businessDay', fn (string $value) => BusinessDay::forDay($value)
            ?? throw (new ModelNotFoundException)->setModel(BusinessDay::class, [$value]));

        // Limitation de débit (§11.1).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('ramymaak.rate_limits.api_per_minute'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(
            (int) config('ramymaak.rate_limits.login_per_minute'),
        )->by($request->ip()));
    }
}
