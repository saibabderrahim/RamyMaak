<?php

use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Problems\ApiException;
use App\Http\Problems\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Temps réel (§9.6) : POST /broadcasting/auth, session Sanctum de la PWA,
    // avec les mêmes contrôles que l'API (durée de session, mot de passe à
    // changer, débit).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => [
        'web', 'auth:sanctum', EnforceSessionPolicy::class, EnsurePasswordIsChanged::class, 'throttle:api',
    ]])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en mode SPA : cookie de session + jeton anti-CSRF (§9.4, §12).
        $middleware->statefulApi();

        // Seul le frontal nginx atteint l'application (§14.2) : on se fie à
        // ses en-têtes X-Forwarded-* pour l'adresse IP réelle et le HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*', 'sanctum/*', 'broadcasting/*')) {
                return ProblemRenderer::render($e);
            }

            return null;
        });
    })->create();
