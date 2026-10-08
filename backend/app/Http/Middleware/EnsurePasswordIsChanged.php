<?php

namespace App\Http\Middleware;

use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changement de mot de passe obligatoire à la première connexion et après
 * toute réinitialisation (EF-AUTH-02) : seules les routes de profil restent
 * accessibles tant que le mot de passe n'a pas été changé.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            throw new ApiException(ErrorCode::PasswordChangeRequired, __('auth.password_change_required'));
        }

        return $next($request);
    }
}
