<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

// Appareils connectés : GET /auth/sessions et DELETE /auth/sessions/{id}
// (contrat du lot 8, §5, I-110, I-103).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 10:00 à Alger (09:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));
    $this->user = userWithRole(Role::Client, ['username' => 'c0012']);
    $this->other = userWithRole(Role::Client, ['username' => 'c0047']);
    $this->chromeAndroid = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
    $this->firefoxWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
    $this->safariIphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    // Connexion depuis un téléphone Android ; les requêtes suivantes portent
    // le cookie de cette session (session courante).
    $this->signIn = function (): string {
        $this->withHeader('User-Agent', $this->chromeAndroid);
        $response = $this->postJson('/api/v1/auth/login', ['username' => 'c0012', 'password' => UserFactory::PASSWORD])->assertOk();
        $id = $response->getCookie(config('session.cookie'))->getValue();
        // Les requêtes JSON des tests n'envoient les cookies qu'avec withCredentials().
        $this->withCredentials()->withCookie(config('session.cookie'), $id);

        return $id;
    };
    $this->device = fn (User $owner, string $id, string $userAgent, CarbonInterface $lastActivity, string $ip = '41.200.10.8') => DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $owner->id,
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity->getTimestamp(),
    ]);
    $this->opaque = fn (string $id) => 's_'.substr(hash_hmac('sha256', $id, (string) config('app.key')), 0, 12);
});

describe('GET /auth/sessions', function () {
    it('lists the live sessions of the account, most recent first, without exposing their identifiers', function () {
        $current = ($this->signIn)();
        ($this->device)($this->user, 'tablet-session', $this->firefoxWindows, now()->subMinutes(30));
        ($this->device)($this->user, 'old-phone-session', $this->safariIphone, now()->subMinutes(90), '41.200.10.9');
        // Expirée (au-delà de la durée de session) ou d'un autre compte : absentes.
        ($this->device)($this->user, 'expired-session', $this->firefoxWindows, now()->subMinutes(121));
        ($this->device)($this->other, 'other-session', $this->firefoxWindows, now()->subMinutes(10));
        PushSubscription::factory()->create(['user_id' => $this->user->id, 'session_id' => 'tablet-session']);
        // Abonnement d'un autre compte rattaché par erreur à la même session : ignoré.
        PushSubscription::factory()->create(['user_id' => $this->other->id, 'session_id' => 'old-phone-session']);

        $response = $this->getJson('/api/v1/auth/sessions')->assertOk();

        expect($response->json('data'))->toBe([
            [
                'id' => ($this->opaque)($current),
                'device' => 'Chrome sur Android',
                'ip' => '127.0.0.1',
                'last_active_at' => '2026-10-08T09:00:00+00:00',
                'current' => true,
                'push' => false,
            ],
            [
                'id' => ($this->opaque)('tablet-session'),
                'device' => 'Firefox sur Windows',
                'ip' => '41.200.10.8',
                'last_active_at' => '2026-10-08T08:30:00+00:00',
                'current' => false,
                'push' => true,
            ],
            [
                'id' => ($this->opaque)('old-phone-session'),
                'device' => 'Safari sur iPhone',
                'ip' => '41.200.10.9',
                'last_active_at' => '2026-10-08T07:30:00+00:00',
                'current' => false,
                'push' => false,
            ],
        ]);
        expect($response->json('data.0.id'))->toMatch('/^s_[0-9a-f]{12}$/')
            ->and($response->getContent())->not->toContain($current)
            ->and($response->getContent())->not->toContain('tablet-session');
    });

    it('flags the current session that has a push subscription', function () {
        $current = ($this->signIn)();
        PushSubscription::factory()->create(['user_id' => $this->user->id, 'session_id' => $current]);

        $this->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.0.push', true);
    });

    it('requires authentication', function () {
        expectProblem($this->getJson('/api/v1/auth/sessions'), 401, 'UNAUTHENTICATED');
    });
});

describe('DELETE /auth/sessions/{id}', function () {
    it('signs another device out: session and its push subscriptions deleted, audit entry (I-103)', function () {
        $current = ($this->signIn)();
        ($this->device)($this->user, 'tablet-session', $this->firefoxWindows, now()->subMinutes(30));
        $tabletPush = PushSubscription::factory()->create(['user_id' => $this->user->id, 'session_id' => 'tablet-session']);
        $currentPush = PushSubscription::factory()->create(['user_id' => $this->user->id, 'session_id' => $current]);
        ($this->device)($this->other, 'other-session', $this->firefoxWindows, now()->subMinutes(10));
        $otherPush = PushSubscription::factory()->create(['user_id' => $this->other->id, 'session_id' => 'other-session']);

        $this->deleteJson('/api/v1/auth/sessions/'.($this->opaque)('tablet-session'))->assertNoContent();

        expect(DB::table('sessions')->where('id', 'tablet-session')->exists())->toBeFalse()
            ->and(PushSubscription::query()->whereKey($tabletPush->id)->exists())->toBeFalse()
            ->and(DB::table('sessions')->where('id', $current)->exists())->toBeTrue()
            ->and(PushSubscription::query()->whereKey([$currentPush->id, $otherPush->id])->count())->toBe(2)
            ->and(DB::table('sessions')->where('id', 'other-session')->exists())->toBeTrue();

        $entry = AuditLog::query()->where('log_name', 'auth')->where('event', 'session.revoked')->sole();
        expect($entry->subject_id)->toBe($this->user->id)
            ->and($entry->properties['session'])->toBe(($this->opaque)('tablet-session'))
            ->and($entry->properties['device'])->toBe('Firefox sur Windows')
            ->and($entry->properties['push_subscriptions_revoked'])->toBe(1)
            ->and($entry->toJson())->not->toContain('tablet-session"');

        $this->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', true);
    });

    it('answers 404 for an unknown, expired, malformed or foreign identifier', function () {
        ($this->signIn)();
        ($this->device)($this->other, 'other-session', $this->firefoxWindows, now()->subMinutes(10));
        ($this->device)($this->user, 'expired-session', $this->firefoxWindows, now()->subMinutes(121));

        foreach (['s_000000000000', ($this->opaque)('other-session'), ($this->opaque)('expired-session'), 'other-session', 's_ABCDEF123456'] as $id) {
            expectProblem($this->deleteJson('/api/v1/auth/sessions/'.$id), 404, 'NOT_FOUND');
        }
        expect(DB::table('sessions')->where('id', 'other-session')->exists())->toBeTrue()
            ->and(AuditLog::query()->where('event', 'session.revoked')->exists())->toBeFalse();
    });

    it('refuses the current session: « Me déconnecter » closes it', function () {
        $current = ($this->signIn)();

        $response = $this->deleteJson('/api/v1/auth/sessions/'.($this->opaque)($current));

        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect($response->json('errors'))->toHaveKey('session')
            ->and(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
    });

    it('requires authentication', function () {
        expectProblem($this->deleteJson('/api/v1/auth/sessions/s_000000000000'), 401, 'UNAUTHENTICATED');
    });
});
