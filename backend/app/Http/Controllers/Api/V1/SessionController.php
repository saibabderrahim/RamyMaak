<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\DeviceLabel;
use App\Domain\Auth\RememberTokens;
use App\Domain\Auth\SessionRevoker;
use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Appareils connectés (contrat du lot 8, §5, I-110, I-113), du plus récent
 * au plus ancien :
 *
 * - les sessions actives du compte (table `sessions`), `remembered` quand un
 *   jeton « Rester connecté » l'a ouverte ; un seul appareil par jeton ;
 * - les appareils mémorisés sans session active (session expirée après
 *   inactivité) : ils se reconnectent par leur cookie, dernière activité =
 *   dernière utilisation du jeton.
 *
 * Les identifiants de session et les jetons ne sont jamais exposés (§12) :
 * `id` en est une empreinte opaque, « s_ » pour une session, « r_ » pour un
 * appareil mémorisé sans session active.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly AuthAudit $audit,
        private readonly RememberTokens $rememberTokens,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $request->session()->getId();
        // Session courante : active en ce moment (elle est enregistrée à la fin
        // de la requête, et peut ne pas l'être encore juste après la connexion).
        $sessions = $this->sessionsOf($user)
            ->reject(fn (object $session) => $session->id === $current)
            ->prepend((object) [
                'id' => $current,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_activity' => now()->getTimestamp(),
            ]);

        // Jeton « Rester connecté » de chaque session qu'il a ouverte.
        $tokens = $this->rememberTokens->active($user);
        $tokenOfSession = [];
        $sessionsOfToken = [];
        foreach ($tokens as $token) {
            $sessionsOfToken[$token->id] = $this->rememberTokens->sessionsOfTokens([(int) $token->id]);
            foreach ($sessionsOfToken[$token->id] as $sessionId) {
                $tokenOfSession[$sessionId] ??= (int) $token->id;
            }
        }

        // Un appareil par jeton : sa session la plus récente (la session courante
        // en tête) ; les autres sessions du même jeton (requêtes parallèles au
        // retour par le cookie) ne sont pas des appareils de plus.
        $listedTokens = [];
        $sessions = $sessions->filter(function (object $session) use ($tokenOfSession, &$listedTokens): bool {
            $token = $tokenOfSession[$session->id] ?? null;
            if ($token === null) {
                return true;
            }
            if (isset($listedTokens[$token])) {
                return false;
            }
            $listedTokens[$token] = true;

            return true;
        });
        $idle = $tokens->reject(fn (object $token) => isset($listedTokens[$token->id]));

        $deviceSessions = fn (?int $token, ?string $sessionId) => $token === null ? [$sessionId] : [...$sessionsOfToken[$token], ...($sessionId === null ? [] : [$sessionId])];
        $withPush = PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('session_id', [...$sessions->pluck('id')->all(), ...array_merge([], ...array_values($sessionsOfToken))])
            ->pluck('session_id')
            ->flip();
        $hasPush = fn (array $sessionIds) => collect($sessionIds)->contains(fn (?string $id) => $id !== null && $withPush->has($id));

        $devices = $sessions
            ->map(fn (object $session) => [
                'id' => self::opaqueId($session->id),
                'device' => DeviceLabel::fromUserAgent($session->user_agent),
                'ip' => $session->ip_address,
                'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity),
                'current' => $session->id === $current,
                'push' => $hasPush($deviceSessions($tokenOfSession[$session->id] ?? null, $session->id)),
                'remembered' => isset($tokenOfSession[$session->id]),
            ])
            ->concat($idle->map(fn (object $token) => [
                'id' => self::rememberedId((int) $token->id),
                'device' => DeviceLabel::fromUserAgent($token->user_agent),
                'ip' => $token->ip_address,
                'last_active_at' => CarbonImmutable::parse($token->last_used_at ?? $token->created_at, 'UTC'),
                'current' => false,
                'push' => $hasPush($deviceSessions((int) $token->id, null)),
                'remembered' => true,
            ]))
            // La session courante (maintenant) reste en tête.
            ->sortByDesc(fn (array $device) => $device['last_active_at']->getTimestamp())
            ->map(fn (array $device) => [...$device, 'last_active_at' => $device['last_active_at']->toIso8601String()])
            ->values();

        return response()->json(['data' => $devices]);
    }

    /**
     * Déconnecte un autre appareil : toutes ses sessions, ses jetons « Rester
     * connecté » (il ne peut plus se reconnecter par son cookie) et ses
     * abonnements push (I-103, I-113). 404 pour un identifiant inconnu ou
     * d'un autre compte ; l'appareil courant se ferme par « Me déconnecter ».
     */
    public function destroy(Request $request, string $session, SessionRevoker $revoker): Response
    {
        /** @var User $user */
        $user = $request->user();
        $target = $this->find($user, $session) ?? throw new NotFoundHttpException;
        if (in_array($request->session()->getId(), $target['sessions'], true)) {
            throw ValidationException::withMessages(['session' => [__('auth.session_current')]]);
        }

        DB::transaction(function () use ($request, $user, $target, $session, $revoker): void {
            $revoked = $revoker->revokeDevice($user, $target['sessions'], $target['tokens']);

            $this->audit->record('session.revoked', 'Appareil déconnecté', $request, $user, properties: [
                'session' => $session,
                'device' => DeviceLabel::fromUserAgent($target['user_agent']),
                'sessions_revoked' => $revoked['sessions'],
                'remember_tokens_revoked' => $revoked['remember_tokens'],
                'push_subscriptions_revoked' => $revoked['push_subscriptions'],
            ]);
        });

        return response()->noContent();
    }

    /**
     * Identifiant exposé d'une session : « s_ » et les 12 premiers caractères
     * hexadécimaux d'un HMAC de son identifiant avec la clé de l'application.
     */
    public static function opaqueId(string $sessionId): string
    {
        return 's_'.substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 12);
    }

    /** Identifiant exposé d'un appareil mémorisé : « r_ » et un HMAC de sa ligne. */
    public static function rememberedId(int $tokenId): string
    {
        return 'r_'.substr(hash_hmac('sha256', 'remember_token:'.$tokenId, (string) config('app.key')), 0, 12);
    }

    /**
     * Appareil du compte désigné par son identifiant exposé : session active
     * (« s_ ») ou jeton non expiré (« r_ »), avec toutes ses sessions.
     *
     * @return array{sessions: list<string>, tokens: list<int>, user_agent: ?string}|null
     */
    private function find(User $user, string $id): ?array
    {
        if (str_starts_with($id, 'r_')) {
            $token = $this->rememberTokens->active($user)->first(fn (object $row) => hash_equals(self::rememberedId((int) $row->id), $id));

            return $token === null ? null : [
                'sessions' => $this->rememberTokens->sessionsOfTokens([(int) $token->id]),
                'tokens' => [(int) $token->id],
                'user_agent' => $token->user_agent,
            ];
        }
        $session = $this->sessionsOf($user)->first(fn (object $row) => hash_equals(self::opaqueId($row->id), $id));

        return $session === null ? null : [
            'sessions' => $this->rememberTokens->deviceSessions($user, $session->id),
            'tokens' => $this->rememberTokens->tokensOfSession($user, $session->id),
            'user_agent' => $session->user_agent,
        ];
    }

    /**
     * Sessions non expirées du compte, de la plus récente à la plus ancienne.
     *
     * @return Collection<int, object{id: string, ip_address: ?string, user_agent: ?string, last_activity: int}>
     */
    private function sessionsOf(User $user): Collection
    {
        $expiredBefore = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $expiredBefore)
            ->orderByDesc('last_activity')
            ->orderBy('id')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);
    }
}
