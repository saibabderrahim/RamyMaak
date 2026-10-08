<?php

namespace App\Domain\Auth;

use App\Domain\Settings\Settings;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Vérification des identifiants (EF-AUTH-01, EF-AUTH-04).
 *
 * Un identifiant inconnu suit exactement le même parcours qu'un compte
 * existant (comptage des échecs, verrouillage, même réponse, même coût de
 * hachage) : les réponses ne révèlent pas si l'identifiant existe.
 */
final class Authenticator
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Hasher $hasher,
        private readonly Cache $cache,
        private readonly AuthAudit $audit,
    ) {}

    /**
     * @throws ApiException
     */
    public function attempt(string $username, string $password, Request $request): User
    {
        $username = User::normalizeUsername($username);
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->failUnknownUser($username, $password, $request);
        }

        if ($user->isLocked()) {
            $this->audit->record('login.failed', 'Connexion refusée : compte verrouillé', $request, $user, properties: [
                'username' => $username,
                'reason' => 'locked',
            ]);

            throw $this->lockedException($user->locked_until->getTimestamp());
        }

        if (! $this->hasher->check($password, $user->password)) {
            $this->registerFailure($user, $request);

            throw $this->invalidCredentials();
        }

        if (! $user->is_active) {
            $this->audit->record('login.failed', 'Connexion refusée : compte désactivé', $request, $user, properties: [
                'username' => $username,
                'reason' => 'disabled',
            ]);

            throw new ApiException(ErrorCode::AccountDisabled, __('auth.disabled'));
        }

        $user->forceFill([
            'failed_logins' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    private function registerFailure(User $user, Request $request): void
    {
        $user->increment('failed_logins');
        $user->refresh();

        $this->audit->record('login.failed', 'Échec de connexion', $request, $user, properties: [
            'username' => $user->username,
            'reason' => 'bad_password',
            'consecutive_failures' => $user->failed_logins,
        ]);

        if ($user->failed_logins >= $this->settings->int('auth.max_failed_logins')) {
            $user->forceFill([
                'failed_logins' => 0,
                'locked_until' => now()->addMinutes($this->settings->int('auth.lockout_minutes')),
            ])->save();

            $this->audit->record('account.locked', 'Compte verrouillé après échecs répétés', $request, $user, properties: [
                'locked_until' => $user->locked_until->toIso8601String(),
            ]);
        }
    }

    private function failUnknownUser(string $username, string $password, Request $request): never
    {
        // Même coût de calcul qu'une vérification réelle.
        $this->hasher->check($password, $this->dummyHash());

        $key = 'auth.unknown-user:'.hash('sha256', $username);
        /** @var array{failed: int, locked_until: ?int} $state */
        $state = $this->cache->get($key, ['failed' => 0, 'locked_until' => null]);
        $properties = ['username' => Str::limit($username, 50, '')];

        if ($state['locked_until'] !== null && $state['locked_until'] > now()->getTimestamp()) {
            $this->audit->record('login.failed', 'Connexion refusée : identifiant inconnu', $request, properties: [
                ...$properties,
                'reason' => 'unknown_user',
            ]);

            throw $this->lockedException($state['locked_until']);
        }

        $state['failed']++;
        if ($state['failed'] >= $this->settings->int('auth.max_failed_logins')) {
            $state = [
                'failed' => 0,
                'locked_until' => now()->addMinutes($this->settings->int('auth.lockout_minutes'))->getTimestamp(),
            ];
        }
        $this->cache->put($key, $state, now()->addDay());

        $this->audit->record('login.failed', 'Échec de connexion : identifiant inconnu', $request, properties: [
            ...$properties,
            'reason' => 'unknown_user',
        ]);

        throw $this->invalidCredentials();
    }

    private function dummyHash(): string
    {
        return $this->cache->rememberForever(
            'auth.dummy-hash:'.config('hashing.driver'),
            fn () => $this->hasher->make(Str::random(32)),
        );
    }

    private function invalidCredentials(): ApiException
    {
        return new ApiException(ErrorCode::InvalidCredentials, __('auth.failed'));
    }

    private function lockedException(int $lockedUntil): ApiException
    {
        $minutes = max(1, (int) ceil(($lockedUntil - now()->getTimestamp()) / 60));

        return new ApiException(
            ErrorCode::AccountLocked,
            trans_choice('auth.locked', $minutes, ['minutes' => $minutes]),
            ['retry_after_minutes' => $minutes],
        );
    }
}
