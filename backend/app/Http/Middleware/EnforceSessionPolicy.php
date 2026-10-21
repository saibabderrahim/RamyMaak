<?php

namespace App\Http\Middleware;

use App\Domain\Settings\Settings;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durée de session par rôle (EF-AUTH-06) : pour l'Admin et l'ADV, durée
 * maximale absolue et expiration après inactivité. Un compte désactivé perd
 * immédiatement l'accès (EF-AUTH-07).
 */
class EnforceSessionPolicy
{
    public const STARTED_AT = 'auth.started_at';

    public const LAST_SEEN_AT = 'auth.last_seen_at';

    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();

        if (! $user->is_active) {
            $this->endSession($request, __('auth.disabled'));
        }

        if ($user->appRole()?->isBackOffice()) {
            $startedAt = (int) $session->get(self::STARTED_AT, $now);
            $lastSeenAt = (int) $session->get(self::LAST_SEEN_AT, $now);

            $absolute = $this->settings->int('sessions.staff_absolute_hours') * 3600;
            $idle = $this->settings->int('sessions.staff_idle_minutes') * 60;

            if ($now - $startedAt > $absolute || $now - $lastSeenAt > $idle) {
                $this->endSession($request, __('auth.session_expired'));
            }
        }

        if (! $session->has(self::STARTED_AT)) {
            $session->put(self::STARTED_AT, $now);
        }
        // La reconnexion du temps réel n'est pas une activité de l'utilisateur.
        if (! $request->is('broadcasting/*')) {
            $session->put(self::LAST_SEEN_AT, $now);
        }

        return $next($request);
    }

    private function endSession(Request $request, string $detail): never
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new ApiException(ErrorCode::Unauthenticated, $detail);
    }
}
