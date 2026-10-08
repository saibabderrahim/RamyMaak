<?php

namespace App\Providers;

use App\Domain\Settings\Settings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Réinitialisé entre deux requêtes ou deux tâches de fond : un worker
        // ne garde pas de paramètres périmés.
        $this->app->scoped(Settings::class);
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
        ]);

        // Limitation de débit (§11.1).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('ramymaak.rate_limits.api_per_minute'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(
            (int) config('ramymaak.rate_limits.login_per_minute'),
        )->by($request->ip()));
    }
}
