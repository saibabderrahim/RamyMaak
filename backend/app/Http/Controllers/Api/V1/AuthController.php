<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\Authenticator;
use App\Domain\Auth\SessionRevoker;
use App\Domain\Push\PushSubscriptions;
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

    public function login(LoginRequest $request, Authenticator $authenticator, Settings $settings): JsonResponse
    {
        $user = $authenticator->attempt(
            (string) $request->input('username'),
            (string) $request->input('password'),
            $request,
        );

        // « Rester connecté » réservé aux clients et régionaux (EF-AUTH-06).
        $remember = $request->boolean('remember') && ! $user->appRole()?->isBackOffice();

        $guard = Auth::guard('web');
        $guard->setRememberDuration($settings->int('sessions.remember_days') * 24 * 60);
        $guard->login($user, $remember);

        // Nouvel identifiant de session à chaque connexion (§12).
        $request->session()->regenerate();
        $request->session()->put(EnforceSessionPolicy::STARTED_AT, now()->getTimestamp());
        $request->session()->put(EnforceSessionPolicy::LAST_SEEN_AT, now()->getTimestamp());

        $this->audit->record('login.succeeded', 'Connexion', $request, $user, properties: [
            'remember' => $remember,
        ]);

        return (new CurrentUserResource($user))->response();
    }

    public function logout(Request $request, PushSubscriptions $pushSubscriptions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->audit->record('logout', 'Déconnexion', $request, $user);
        // Abonnements push de cet appareil (I-103), avant que la session ne change d'identifiant.
        $pushSubscriptions->forgetSession($user, $request->session()->getId());
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

    public function changePassword(ChangePasswordRequest $request): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $user): void {
            $user->forceFill([
                'password' => $request->input('password'),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            $this->audit->record('password.changed', 'Mot de passe modifié', $request, $user);
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
