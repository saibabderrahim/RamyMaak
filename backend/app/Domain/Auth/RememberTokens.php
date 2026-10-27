<?php

namespace App\Domain\Auth;

use App\Domain\Push\PushSubscriptions;
use App\Domain\Settings\Settings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jetons « Rester connecté » par appareil (EF-AUTH-06, contrat du lot 8 §5,
 * I-113). Chaque connexion avec « Rester connecté » crée le jeton de cet
 * appareil, porté par son cookie et enregistré haché (SHA-256) ; la
 * déconnexion d'un appareil supprime le sien, les autres restent connectés.
 *
 * - Durée : celle du cookie (`sessions.remember_days`, depuis la
 *   connexion) ; un jeton plus ancien est refusé et supprimé.
 * - Sessions : chaque jeton garde les sessions qu'il a ouvertes (à la
 *   connexion, puis à chaque retour par le cookie après expiration de la
 *   session, `remember_token_sessions`) et la dernière d'entre elles. Un
 *   appareil est l'ensemble de ces sessions : le déconnecter les ferme toutes,
 *   y compris celles nées de requêtes parallèles au retour.
 * - Réservé aux clients et aux régionaux : un compte passé au back-office
 *   n'est plus reconnu par ses anciens jetons.
 */
final class RememberTokens
{
    public const TABLE = 'remember_tokens';

    public const SESSIONS = 'remember_token_sessions';

    public function __construct(
        private readonly Settings $settings,
        private readonly PushSubscriptions $pushSubscriptions,
    ) {}

    /**
     * Jeton de l'appareil à la connexion (SessionGuard::cycleRememberToken) :
     * nouvelle ligne, ou nouveau jeton pour la ligne de l'appareil déjà
     * reconnu pendant la requête.
     */
    public function store(User $user, string $token): void
    {
        $id = $user->rememberTokenId();
        if ($id !== null) {
            DB::table(self::TABLE)->where('id', $id)->update(['token_hash' => self::hash($token), 'updated_at' => now()]);
            $user->rememberDevice($id, $token);

            return;
        }

        // Jetons expirés du compte : purgés à chaque nouvelle connexion.
        DB::table(self::TABLE)->where('user_id', $user->id)->where('created_at', '<', $this->expiredBefore())->delete();
        $request = request();
        $id = DB::table(self::TABLE)->insertGetId([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            'session_id' => null,
            ...self::device($request),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->rememberDevice((int) $id, $token);
    }

    /**
     * Retour par le cookie (SessionGuard::userFromRecaller) : jeton du compte,
     * non expiré, compte de terrain. Rien n'est mis à jour ici : le guard
     * vérifie ensuite l'empreinte du mot de passe du cookie (onLogin).
     */
    public function recall(User $user, string $token): bool
    {
        $hash = self::hash($token);
        $row = DB::table(self::TABLE)->where('user_id', $user->id)->where('token_hash', $hash)->first();
        if ($row === null || ! hash_equals((string) $row->token_hash, $hash)) {
            return false;
        }
        if (CarbonImmutable::parse($row->created_at, 'UTC')->lessThan($this->expiredBefore()) || $user->appRole()?->isBackOffice()) {
            DB::table(self::TABLE)->where('id', $row->id)->delete();

            return false;
        }

        $user->rememberDevice((int) $row->id, $token);

        return true;
    }

    /** Session ouverte par le jeton de l'appareil de la requête en cours. */
    public function attachSession(User $user, string $sessionId): void
    {
        $id = $user->rememberTokenId();
        if ($id === null) {
            return;
        }
        DB::table(self::TABLE)->where('id', $id)->update(['session_id' => $sessionId, 'updated_at' => now()]);
        DB::table(self::SESSIONS)->insertOrIgnore(['remember_token_id' => $id, 'session_id' => $sessionId, 'created_at' => now()]);
    }

    /**
     * Retour par le cookie, entièrement vérifié (événement Login du guard) :
     * dernière utilisation, navigateur et nouvelle session de l'appareil ; ses
     * abonnements push suivent la nouvelle session (I-103). Une connexion par
     * le formulaire rattache sa session elle-même (AuthController::login).
     */
    public function onLogin(Login $event): void
    {
        $guard = Auth::guard($event->guard);
        $user = $event->user;
        if (! $event->remember || ! $user instanceof User || ! $guard instanceof SessionGuard || ! $guard->viaRemember()) {
            return;
        }
        $id = $user->rememberTokenId();
        if ($id === null) {
            return;
        }

        $sessionId = $guard->getSession()->getId();
        DB::table(self::TABLE)->where('id', $id)->update([
            ...self::device(request()),
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);
        $this->pushSubscriptions->moveSessions($user, $this->sessionsOfTokens([$id]), $sessionId);
        $this->attachSession($user, $sessionId);
    }

    /**
     * Garde « Rester connecté » sur l'appareil de la requête après un
     * changement de mot de passe : le cookie porte une empreinte du mot de
     * passe, il est réémis avec la nouvelle. Renvoie le jeton gardé, ou null
     * si l'appareil n'a pas de cookie valable pour ce compte.
     */
    public function keepCurrentDevice(User $user, Request $request): ?int
    {
        $guard = Auth::guard('web');
        if (! $guard instanceof SessionGuard) {
            return null;
        }
        $value = $request->cookies->get($guard->getRecallerName());
        $recaller = is_string($value) ? new Recaller($value) : null;
        if ($recaller === null || ! $recaller->valid() || (string) $recaller->id() !== (string) $user->getAuthIdentifier()) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('user_id', $user->id)->where('token_hash', self::hash($recaller->token()))->first();
        if ($row === null) {
            return null;
        }

        $expiresAt = CarbonImmutable::parse($row->created_at, 'UTC')->addDays($this->settings->int('sessions.remember_days'));
        Cookie::queue(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$recaller->token().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            max(1, (int) now()->diffInMinutes($expiresAt)),
        );

        return (int) $row->id;
    }

    /**
     * Sessions de l'appareil d'une session : elle-même et, si un jeton
     * « Rester connecté » l'a ouverte, toutes les sessions de ce jeton.
     *
     * @return list<string>
     */
    public function deviceSessions(User $user, string $sessionId): array
    {
        return array_values(array_unique([$sessionId, ...$this->sessionsOfTokens($this->tokensOfSession($user, $sessionId))]));
    }

    /**
     * Jetons du compte qui ont ouvert cette session.
     *
     * @return list<int>
     */
    public function tokensOfSession(User $user, string $sessionId): array
    {
        return DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->where('session_id', $sessionId)
                ->orWhereIn('id', DB::table(self::SESSIONS)->select('remember_token_id')->where('session_id', $sessionId)))
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
    }

    /**
     * Sessions ouvertes par ces jetons.
     *
     * @param  list<int>  $tokenIds
     * @return list<string>
     */
    public function sessionsOfTokens(array $tokenIds): array
    {
        if ($tokenIds === []) {
            return [];
        }

        return array_values(array_unique([
            ...DB::table(self::SESSIONS)->whereIn('remember_token_id', $tokenIds)->pluck('session_id')->all(),
            ...DB::table(self::TABLE)->whereIn('id', $tokenIds)->whereNotNull('session_id')->pluck('session_id')->all(),
        ]));
    }

    /**
     * Jeton du cookie de la requête (appareil qui se reconnecte, ou se
     * déconnecte) : supprimé, quel que soit le compte qui l'avait créé.
     */
    public function forgetCookie(Request $request): int
    {
        $value = $request->cookies->get(Auth::guard('web')->getRecallerName());
        if (! is_string($value)) {
            return 0;
        }
        $recaller = new Recaller($value);

        return $recaller->valid() ? DB::table(self::TABLE)->where('token_hash', self::hash($recaller->token()))->delete() : 0;
    }

    /**
     * Déconnexion de cet appareil : son jeton (cookie, ligne reconnue
     * pendant la requête, session courante).
     */
    public function forgetCurrentDevice(User $user, Request $request): int
    {
        $deleted = $this->forgetCookie($request);
        if ($user->rememberTokenId() !== null) {
            $deleted += $this->forget($user, $user->rememberTokenId());
        }
        if ($request->hasSession()) {
            $deleted += $this->forgetSession($user, $request->session()->getId());
        }
        // SessionGuard::logout ne renouvelle plus rien pour cet appareil.
        $user->forgetRememberedDevice();

        return $deleted;
    }

    public function forget(User $user, int $id): int
    {
        return DB::table(self::TABLE)->where('user_id', $user->id)->where('id', $id)->delete();
    }

    /** Jetons qui ont ouvert une session du compte (appareil déconnecté). */
    public function forgetSession(User $user, string $sessionId): int
    {
        return $this->forgetTokens($user, $this->tokensOfSession($user, $sessionId));
    }

    /**
     * @param  list<int>  $tokenIds
     */
    public function forgetTokens(User $user, array $tokenIds): int
    {
        return $tokenIds === [] ? 0 : DB::table(self::TABLE)->where('user_id', $user->id)->whereIn('id', $tokenIds)->delete();
    }

    /** Jetons du compte, sauf celui de l'appareil gardé (changement de mot de passe). */
    public function forgetAllBut(User $user, ?int $keptTokenId): int
    {
        return DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->when($keptTokenId !== null, fn ($query) => $query->where('id', '!=', $keptTokenId))
            ->delete();
    }

    /** Tous les jetons du compte (déconnexion de tous les appareils, désactivation, réinitialisation). */
    public function forgetUser(User $user): int
    {
        return DB::table(self::TABLE)->where('user_id', $user->id)->delete();
    }

    /**
     * Jetons non expirés du compte, du plus récemment utilisé au plus ancien.
     *
     * @return Collection<int, object{id: int, session_id: ?string, user_agent: ?string, ip_address: ?string, last_used_at: ?string, created_at: string}>
     */
    public function active(User $user): Collection
    {
        return DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $this->expiredBefore())
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get(['id', 'session_id', 'user_agent', 'ip_address', 'last_used_at', 'created_at']);
    }

    /** Empreinte enregistrée d'un jeton. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Création la plus ancienne d'un jeton encore valable (durée du cookie). */
    private function expiredBefore(): CarbonImmutable
    {
        return now()->toImmutable()->utc()->subDays($this->settings->int('sessions.remember_days'));
    }

    /**
     * Navigateur et adresse de l'appareil.
     *
     * @return array{user_agent: ?string, ip_address: ?string}
     */
    private static function device(?Request $request): array
    {
        $userAgent = (string) $request?->userAgent();

        return [
            'user_agent' => $userAgent === '' ? null : Str::limit($userAgent, 255, ''),
            'ip_address' => $request?->ip(),
        ];
    }
}
