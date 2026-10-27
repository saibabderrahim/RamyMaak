<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\DeviceLabel;
use App\Domain\Auth\RememberTokens;
use App\Domain\Push\PushSubscriptions;
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
 *   jeton « Rester connecté » y est rattaché ;
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
        $sessionIds = $sessions->pluck('id')->all();
        $tokens = $this->rememberTokens->active($user);
        $remembered = $tokens->pluck('session_id')->filter()->flip();
        $idle = $tokens->reject(fn (object $token) => $token->session_id !== null && in_array($token->session_id, $sessionIds, true));
        $withPush = PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('session_id', [...$sessionIds, ...$idle->pluck('session_id')->filter()->all()])
            ->pluck('session_id')
            ->flip();

        $devices = $sessions
            ->map(fn (object $session) => [
                'id' => self::opaqueId($session->id),
                'device' => DeviceLabel::fromUserAgent($session->user_agent),
                'ip' => $session->ip_address,
                'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity),
                'current' => $session->id === $current,
                'push' => $withPush->has($session->id),
                'remembered' => $remembered->has($session->id),
            ])
            ->concat($idle->map(fn (object $token) => [
                'id' => self::rememberedId((int) $token->id),
                'device' => DeviceLabel::fromUserAgent($token->user_agent),
                'ip' => $token->ip_address,
                'last_active_at' => CarbonImmutable::parse($token->last_used_at ?? $token->created_at, 'UTC'),
                'current' => false,
                'push' => $token->session_id !== null && $withPush->has($token->session_id),
                'remembered' => true,
            ]))
            // La session courante (maintenant) reste en tête.
            ->sortByDesc(fn (array $device) => $device['last_active_at']->getTimestamp())
            ->map(fn (array $device) => [...$device, 'last_active_at' => $device['last_active_at']->toIso8601String()])
            ->values();

        return response()->json(['data' => $devices]);
    }

    /**
     * Déconnecte un autre appareil : sa session, ses jetons « Rester
     * connecté » (il ne peut plus se reconnecter par son cookie) et ses
     * abonnements push (I-103, I-113). 404 pour un identifiant inconnu ou
     * d'un autre compte ; la session courante se ferme par « Me déconnecter ».
     */
    public function destroy(Request $request, string $session, PushSubscriptions $pushSubscriptions): Response
    {
        /** @var User $user */
        $user = $request->user();
        $target = $this->find($user, $session) ?? throw new NotFoundHttpException;
        if ($target['session_id'] !== null && $target['session_id'] === $request->session()->getId()) {
            throw ValidationException::withMessages(['session' => [__('auth.session_current')]]);
        }

        DB::transaction(function () use ($request, $user, $target, $session, $pushSubscriptions): void {
            $tokens = $target['token_id'] === null ? 0 : $this->rememberTokens->forget($user, $target['token_id']);
            $subscriptions = 0;
            if ($target['session_id'] !== null) {
                DB::table(config('session.table', 'sessions'))
                    ->where('id', $target['session_id'])
                    ->where('user_id', $user->id)
                    ->delete();
                $tokens += $this->rememberTokens->forgetSession($user, $target['session_id']);
                $subscriptions = $pushSubscriptions->forgetSession($user, $target['session_id']);
            }

            $this->audit->record('session.revoked', 'Appareil déconnecté', $request, $user, properties: [
                'session' => $session,
                'device' => DeviceLabel::fromUserAgent($target['user_agent']),
                'remember_tokens_revoked' => $tokens,
                'push_subscriptions_revoked' => $subscriptions,
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
     * (« s_ ») ou jeton non expiré (« r_ »), avec sa dernière session.
     *
     * @return array{session_id: ?string, token_id: ?int, user_agent: ?string}|null
     */
    private function find(User $user, string $id): ?array
    {
        if (str_starts_with($id, 'r_')) {
            $token = $this->rememberTokens->active($user)->first(fn (object $row) => hash_equals(self::rememberedId((int) $row->id), $id));

            return $token === null ? null : ['session_id' => $token->session_id, 'token_id' => (int) $token->id, 'user_agent' => $token->user_agent];
        }
        $session = $this->sessionsOf($user)->first(fn (object $row) => hash_equals(self::opaqueId($row->id), $id));

        return $session === null ? null : ['session_id' => $session->id, 'token_id' => null, 'user_agent' => $session->user_agent];
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
