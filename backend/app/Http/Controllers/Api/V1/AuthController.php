<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\Authenticator;
use App\Domain\Auth\RememberTokens;
use App\Domain\Auth\SessionRevoker;
use App\Domain\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function __construct(private readonly AuthAudit $audit) {}

    public function login(LoginRequest $request, Authenticator $authenticator, Settings $settings, RememberTokens $rememberTokens): JsonResponse
    {
        $user = $authenticator->attempt(
            (string) $request->input('username'),
            (string) $request->input('password'),
            $request,
        );

        // « Rester connecté » réservé aux clients et régionaux (EF-AUTH-06).
        $remember = $request->boolean('remember') && ! $user->appRole()?->isBackOffice();

        // Ancien jeton de cet appareil (I-113) : remplacé par cette connexion.
        $rememberTokens->forgetCookie($request);
        $guard = Auth::guard('web');
        $guard->setRememberDuration($settings->int('sessions.remember_days') * 24 * 60);
        $guard->login($user, $remember);

        // Nouvel identifiant de session à chaque connexion (§12).
        $request->session()->regenerate();
        $request->session()->put(EnforceSessionPolicy::STARTED_AT, now()->getTimestamp());
        $request->session()->put(EnforceSessionPolicy::LAST_SEEN_AT, now()->getTimestamp());
        // Le jeton de l'appareil suit la session définitive.
        if ($remember) {
            $rememberTokens->attachSession($user, $request->session()->getId());
        }

        $this->audit->record('login.succeeded', 'Connexion', $request, $user, properties: [
            'remember' => $remember,
        ]);

        return (new CurrentUserResource($user))->response();
    }

    public function logout(Request $request, SessionRevoker $revoker, RememberTokens $rememberTokens): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->audit->record('logout', 'Déconnexion', $request, $user);
        // Sessions, abonnements push et jeton « Rester connecté » de cet appareil
        // (I-103, I-113), avant que la session ne change d'identifiant : y compris
        // les sessions ouvertes par son jeton à un retour précédent.
        $sessions = $rememberTokens->deviceSessions($user, $request->session()->getId());
        $revoker->revokeDevice($user, $sessions, $rememberTokens->tokensOfSession($user, $request->session()->getId()));
        $rememberTokens->forgetCurrentDevice($user, $request);
        $this->endCurrentSession($request);

        return response()->noContent();
    }

    public function logoutAll(Request $request, SessionRevoker $revoker): Response
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $revoker->revokeAll($user);
        $this->audit->record('logout.all', 'Déconnexion de tous les appareils', $request, $user, properties: [
            'sessions_revoked' => $revoked,
        ]);
        $this->endCurrentSession($request);

        return response()->noContent();
    }

    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }

    public function changePassword(ChangePasswordRequest $request, SessionRevoker $revoker, RememberTokens $rememberTokens): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $user, $revoker, $rememberTokens): void {
            $user->forceFill([
                'password' => $request->input('password'),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            // Les cookies « Rester connecté » portent une empreinte du mot de passe :
            // celui de cet appareil est réémis, les autres appareils sont déconnectés
            // (sessions, jetons, abonnements push) au lieu de rester listés (I-113).
            $kept = $rememberTokens->keepCurrentDevice($user, $request);
            $revoked = $revoker->revokeOtherDevices($user, $request->session()->getId(), $kept);

            $this->audit->record('password.changed', 'Mot de passe modifié', $request, $user, properties: [
                'other_devices' => $revoked,
            ]);
        });

        return new CurrentUserResource($user);
    }

    private function endCurrentSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
