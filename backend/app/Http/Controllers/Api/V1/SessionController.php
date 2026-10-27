<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\DeviceLabel;
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
 * Appareils connectés (contrat du lot 8, §5, I-110) : sessions du compte
 * (table `sessions`), de la plus récente à la plus ancienne. L'identifiant de
 * session n'est jamais exposé (§12) : `id` en est une empreinte opaque.
 * Une session expirée (inactive depuis plus que la durée de session) n'est
 * plus un appareil connecté : elle n'est pas listée.
 */
class SessionController extends Controller
{
    public function __construct(private readonly AuthAudit $audit) {}

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
        $withPush = PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('session_id', $sessions->pluck('id')->all())
            ->pluck('session_id')
            ->flip();

        $data = $sessions->map(fn (object $session) => [
            'id' => self::opaqueId($session->id),
            'device' => DeviceLabel::fromUserAgent($session->user_agent),
            'ip' => $session->ip_address,
            'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity)->toIso8601String(),
            'current' => $session->id === $current,
            'push' => $withPush->has($session->id),
        ]);

        return response()->json(['data' => $data->values()]);
    }

    /**
     * Déconnecte un autre appareil : session et abonnements push de cette
     * session supprimés (I-103). 404 pour un identifiant inconnu ou d'un
     * autre compte ; la session courante se ferme par « Me déconnecter ».
     */
    public function destroy(Request $request, string $session): Response
    {
        /** @var User $user */
        $user = $request->user();
        $target = $this->sessionsOf($user)->first(fn (object $row) => hash_equals(self::opaqueId($row->id), $session));
        if ($target === null) {
            throw new NotFoundHttpException;
        }
        if ($target->id === $request->session()->getId()) {
            throw ValidationException::withMessages(['session' => [__('auth.session_current')]]);
        }

        DB::transaction(function () use ($request, $user, $target, $session): void {
            DB::table(config('session.table', 'sessions'))
                ->where('id', $target->id)
                ->where('user_id', $user->id)
                ->delete();
            $subscriptions = PushSubscription::query()
                ->where('user_id', $user->id)
                ->where('session_id', $target->id)
                ->delete();

            $this->audit->record('session.revoked', 'Appareil déconnecté', $request, $user, properties: [
                'session' => $session,
                'device' => DeviceLabel::fromUserAgent($target->user_agent),
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
