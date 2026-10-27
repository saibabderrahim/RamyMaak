<?php

namespace App\Domain\Auth;

use App\Domain\Settings\Settings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
 * - Session : chaque jeton garde la dernière session qu'il a ouverte (à la
 *   connexion, puis au retour par le cookie après expiration de la session),
 *   pour la liste des appareils et la déconnexion d'un appareil.
 * - Réservé aux clients et aux régionaux : un compte passé au back-office
 *   n'est plus reconnu par ses anciens jetons.
 */
final class RememberTokens
{
    public const TABLE = 'remember_tokens';

    public function __construct(private readonly Settings $settings) {}

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
     * non expiré, compte de terrain. Met à jour la dernière utilisation.
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

        DB::table(self::TABLE)->where('id', $row->id)->update([
            ...self::device(request()),
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);
        $user->rememberDevice((int) $row->id, $token);

        return true;
    }

    /** Session ouverte par le jeton de l'appareil de la requête en cours. */
    public function attachSession(User $user, string $sessionId): void
    {
        if ($user->rememberTokenId() !== null) {
            DB::table(self::TABLE)->where('id', $user->rememberTokenId())->update(['session_id' => $sessionId, 'updated_at' => now()]);
        }
    }

    /**
     * Connexion par le cookie (événement Login, `remember`) : le jeton est
     * rattaché à la nouvelle session.
     */
    public function onLogin(Login $event): void
    {
        $guard = Auth::guard($event->guard);
        if ($event->remember && $event->user instanceof User && $guard instanceof SessionGuard) {
            $this->attachSession($event->user, $guard->getSession()->getId());
        }
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

    /** Jetons rattachés à une session du compte (appareil déconnecté depuis la liste). */
    public function forgetSession(User $user, string $sessionId): int
    {
        return DB::table(self::TABLE)->where('user_id', $user->id)->where('session_id', $sessionId)->delete();
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
