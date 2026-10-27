<?php

use App\Domain\Access\Role;
use App\Http\Controllers\Api\V1\SessionController;
use App\Models\AuditLog;
use App\Models\PushSubscription;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// « Rester connecté » par appareil (EF-AUTH-06, contrat du lot 8 §5, I-113) :
// un jeton par connexion, supprimé avec l'appareil.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 08:00 à Alger (07:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'Africa/Algiers'));
    $this->user = userWithRole(Role::Client, ['username' => 'c0012']);
    $this->android = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
    $this->windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
    $this->session = config('session.cookie');
    $this->recaller = Auth::guard('web')->getRecallerName();

    // Requête d'un appareil : dans les tests, l'application (gardes, session
    // en mémoire, cookies en attente) est partagée entre requêtes ; chaque
    // appareil repart de zéro avec ses seuls cookies.
    $this->asDevice = function (string $userAgent, array $cookies = []): void {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('cookie')->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->withCredentials()->withHeader('User-Agent', $userAgent)->withCookies($cookies);
    };
    // Connexion depuis un nouvel appareil ; cookies de session et « Rester connecté ».
    $this->signIn = function (string $userAgent, bool $remember = true, string $username = 'c0012', array $cookies = []): array {
        ($this->asDevice)($userAgent, $cookies);
        $response = $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => UserFactory::PASSWORD, 'remember' => $remember])->assertOk();
        $device = [$this->session => $response->getCookie($this->session)->getValue()];
        if (($recaller = $response->getCookie($this->recaller)) !== null) {
            $device[$this->recaller] = $recaller->getValue();
        }

        return $device;
    };
    // Jeton en clair porté par le cookie « id|jeton|empreinte du mot de passe ».
    $this->tokenOf = fn (array $device) => explode('|', $device[$this->recaller])[1];
    $this->tokens = fn () => DB::table('remember_tokens')->orderBy('id')->get();
    $this->me = fn () => $this->getJson('/api/v1/auth/me');
});

it('creates one token per device at each login with « Rester connecté », stored hashed', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);

    $tokens = ($this->tokens)();
    expect($tokens)->toHaveCount(2)
        ->and($tokens->pluck('user_id')->unique()->all())->toBe([$this->user->id])
        ->and($tokens->pluck('token_hash')->all())->toBe([hash('sha256', ($this->tokenOf)($phone)), hash('sha256', ($this->tokenOf)($tablet))])
        ->and($tokens->pluck('session_id')->all())->toBe([$phone[$this->session], $tablet[$this->session]])
        ->and($tokens->pluck('user_agent')->all())->toBe([$this->android, $this->windows])
        ->and($tokens[0]->ip_address)->toBe('127.0.0.1')
        // Le jeton n'est jamais enregistré en clair.
        ->and($tokens->toJson())->not->toContain(($this->tokenOf)($phone));
});

it('signs a remembered device back in after its session expired, and links the new session', function () {
    $phone = ($this->signIn)($this->android);

    // Session expirée (2 h) : le cookie « Rester connecté » reconnecte l'appareil.
    $this->travel(3)->hours();
    ($this->asDevice)($this->android, $phone);
    $response = ($this->me)()->assertOk()->assertJsonPath('id', $this->user->id);

    $session = $response->getCookie($this->session)->getValue();
    $token = ($this->tokens)()->sole();
    expect($session)->not->toBe($phone[$this->session])
        ->and($token->session_id)->toBe($session)
        ->and(CarbonImmutable::parse($token->last_used_at, 'UTC')->toIso8601String())->toBe('2026-10-08T10:00:00+00:00');

    // La nouvelle session suffit ensuite.
    ($this->asDevice)($this->android, [$this->session => $session]);
    ($this->me)()->assertOk();
});

it('replaces the token of a device that signs in again', function () {
    $phone = ($this->signIn)($this->android);

    $again = ($this->signIn)($this->android, cookies: $phone);

    expect(($this->tokens)()->sole()->token_hash)->toBe(hash('sha256', ($this->tokenOf)($again)));
});

it('does not sign back in a device signed out from the list (I-113)', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);
    ($this->asDevice)($this->windows, $tablet);
    $devices = collect($this->getJson('/api/v1/auth/sessions')->assertOk()->json('data'));
    $phoneItem = $devices->firstWhere('device', 'Chrome sur Android');
    expect($phoneItem['id'])->toStartWith('s_')
        ->and($phoneItem['remembered'])->toBeTrue()
        ->and($devices->firstWhere('current', true)['remembered'])->toBeTrue();

    $this->deleteJson('/api/v1/auth/sessions/'.$phoneItem['id'])->assertNoContent();

    expect(($this->tokens)()->pluck('session_id')->all())->toBe([$tablet[$this->session]])
        ->and(DB::table('sessions')->where('id', $phone[$this->session])->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'session.revoked')->sole()->properties['remember_tokens_revoked'])->toBe(1);
    // Ni par sa session, ni par son cookie « Rester connecté ».
    ($this->asDevice)($this->android, $phone);
    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
    ($this->asDevice)($this->windows, $tablet);
    ($this->me)()->assertOk();
});

it('deletes only the token of the device that logs out', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);

    ($this->asDevice)($this->android, $phone);
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(($this->tokens)()->pluck('token_hash')->all())->toBe([hash('sha256', ($this->tokenOf)($tablet))]);
    ($this->asDevice)($this->android, [$this->recaller => $phone[$this->recaller]]);
    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
    ($this->asDevice)($this->windows, [$this->recaller => $tablet[$this->recaller]]);
    ($this->me)()->assertOk();
});

it('deletes every token of the account on logout-all, deactivation and password reset', function (string $action) {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);

    match ($action) {
        'logout-all' => (function () use ($phone) {
            ($this->asDevice)($this->android, $phone);
            $this->postJson('/api/v1/auth/logout-all')->assertNoContent();
        })(),
        'deactivation' => (function () {
            ($this->asDevice)($this->windows);
            $this->actingAs(admin())->patchJson("/api/v1/admin/users/{$this->user->id}", ['is_active' => false])->assertOk();
        })(),
        'password reset' => (function () {
            ($this->asDevice)($this->windows);
            $this->actingAs(admin())->postJson("/api/v1/admin/users/{$this->user->id}/reset-password")->assertOk();
        })(),
    };

    expect(($this->tokens)())->toBeEmpty();
    ($this->asDevice)($this->windows, [$this->recaller => $tablet[$this->recaller]]);
    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
})->with(['logout-all', 'deactivation', 'password reset']);

it('lists a remembered device whose session expired, and signs it out by its « r_ » identifier', function () {
    $phone = ($this->signIn)($this->android);
    PushSubscription::factory()->create(['user_id' => $this->user->id, 'session_id' => $phone[$this->session]]);
    $tokenId = ($this->tokens)()->sole()->id;

    $this->travel(3)->hours();
    $tablet = ($this->signIn)($this->windows, remember: false);
    ($this->asDevice)($this->windows, $tablet);
    $response = $this->getJson('/api/v1/auth/sessions')->assertOk();

    $idleId = 'r_'.substr(hash_hmac('sha256', 'remember_token:'.$tokenId, (string) config('app.key')), 0, 12);
    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->toMatchArray(['current' => true, 'remembered' => false, 'device' => 'Firefox sur Windows'])
        ->and($response->json('data.1'))->toBe([
            'id' => $idleId,
            'device' => 'Chrome sur Android',
            'ip' => '127.0.0.1',
            'last_active_at' => '2026-10-08T07:00:00+00:00',
            'current' => false,
            'push' => true,
            'remembered' => true,
        ])
        ->and($response->getContent())->not->toContain($phone[$this->session]);

    $this->deleteJson('/api/v1/auth/sessions/'.$idleId)->assertNoContent();

    expect(($this->tokens)())->toBeEmpty()
        ->and(PushSubscription::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
    expectProblem($this->deleteJson('/api/v1/auth/sessions/'.$idleId), 404, 'NOT_FOUND');
    ($this->asDevice)($this->android, $phone);
    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
});

it('refuses to sign out the current device by its token, and foreign tokens', function () {
    $phone = ($this->signIn)($this->android);
    userWithRole(Role::Client, ['username' => 'c0047']);
    ($this->signIn)($this->windows, username: 'c0047');
    $rememberedId = fn (int $id) => 'r_'.substr(hash_hmac('sha256', 'remember_token:'.$id, (string) config('app.key')), 0, 12);
    [$mine, $foreign] = ($this->tokens)()->pluck('id')->all();

    ($this->asDevice)($this->android, $phone);
    expectProblem($this->deleteJson('/api/v1/auth/sessions/'.$rememberedId($mine)), 422, 'VALIDATION_FAILED');
    expectProblem($this->deleteJson('/api/v1/auth/sessions/'.$rememberedId($foreign)), 404, 'NOT_FOUND');

    expect(($this->tokens)())->toHaveCount(2);
});

it('never remembers back-office accounts, nor an account moved to the back office since', function () {
    admin(['username' => 'boss']);
    $admin = ($this->signIn)($this->windows, username: 'boss');
    expect($admin)->not->toHaveKey($this->recaller)
        ->and(($this->tokens)())->toBeEmpty();

    $phone = ($this->signIn)($this->android);
    $this->user->syncRoles([Role::Adv->value]);
    $this->travel(3)->hours();
    ($this->asDevice)($this->android, $phone);

    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
    expect(($this->tokens)())->toBeEmpty();
});

it('refuses a token older than the remember-me duration', function () {
    $phone = ($this->signIn)($this->android);

    $this->travel(31)->days();
    ($this->asDevice)($this->android, $phone);

    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
    expect(($this->tokens)())->toBeEmpty();
});

it('treats the sessions opened by parallel requests on return as one device, and signs them all out', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);

    // Session expirée : au retour au premier plan, la PWA envoie plusieurs requêtes
    // à la fois, chacune se reconnecte par le cookie et ouvre sa propre session.
    $this->travel(3)->hours();
    $sessions = [];
    foreach ([1, 2] as $request) {
        ($this->asDevice)($this->android, $phone);
        $sessions[] = ($this->me)()->assertOk()->getCookie($this->session)->getValue();
    }
    expect($sessions[0])->not->toBe($sessions[1]);

    // L'appareil lui-même ne se voit qu'une fois, comme sur la tablette.
    ($this->asDevice)($this->android, [$this->session => $sessions[0], $this->recaller => $phone[$this->recaller]]);
    $own = collect($this->getJson('/api/v1/auth/sessions')->assertOk()->json('data'));
    expect($own->where('device', 'Chrome sur Android'))->toHaveCount(1)
        ->and($own->firstWhere('current', true)['remembered'])->toBeTrue();
    ($this->asDevice)($this->windows, $tablet);
    $devices = collect($this->getJson('/api/v1/auth/sessions')->assertOk()->json('data'));
    $phoneItem = $devices->where('device', 'Chrome sur Android')->sole();
    expect($phoneItem['remembered'])->toBeTrue();
    // Sa session « jumelle » est aussi l'appareil : elle ne se révoque pas depuis l'appareil même.
    ($this->asDevice)($this->android, [$this->session => $sessions[0]]);
    expectProblem($this->deleteJson('/api/v1/auth/sessions/'.SessionController::opaqueId($sessions[1])), 422, 'VALIDATION_FAILED');

    ($this->asDevice)($this->windows, $tablet);
    $this->deleteJson('/api/v1/auth/sessions/'.$phoneItem['id'])->assertNoContent();

    // Aucune des deux sessions, ni le cookie, ne reconnecte l'appareil.
    foreach ($sessions as $session) {
        ($this->asDevice)($this->android, [$this->session => $session, $this->recaller => $phone[$this->recaller]]);
        expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
    }
    expect(($this->tokens)()->pluck('user_agent')->all())->toBe([$this->windows]);
});

it('keeps the push subscription of a device signed back in by its cookie with its new session, and removes it on logout (I-103)', function () {
    $phone = ($this->signIn)($this->android);
    $subscription = PushSubscription::factory()->for($this->user)->create(['session_id' => $phone[$this->session]]);

    $this->travel(3)->hours();
    ($this->asDevice)($this->android, $phone);
    $session = ($this->me)()->assertOk()->getCookie($this->session)->getValue();
    expect($subscription->fresh()->session_id)->toBe($session);

    // Application reprise depuis l'arrière-plan : pas de nouvel envoi de l'abonnement.
    ($this->asDevice)($this->android, [$this->session => $session, $this->recaller => $phone[$this->recaller]]);
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(0)
        ->and(($this->tokens)())->toBeEmpty();
});

it('signs the other devices out on a password change, and keeps « Rester connecté » on this one', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);
    $pc = ($this->signIn)($this->windows, remember: false);
    PushSubscription::factory()->for($this->user)->create(['session_id' => $phone[$this->session]]);
    PushSubscription::factory()->for($this->user)->create(['session_id' => $tablet[$this->session]]);

    ($this->asDevice)($this->android, $phone);
    $response = $this->putJson('/api/v1/auth/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'Nouveau-mot-de-passe-2026',
        'password_confirmation' => 'Nouveau-mot-de-passe-2026',
    ])->assertOk();
    $recaller = $response->getCookie($this->recaller);
    expect($recaller)->not->toBeNull()
        ->and(explode('|', $recaller->getValue())[1])->toBe(($this->tokenOf)($phone));

    expect(($this->tokens)()->pluck('session_id')->all())->toBe([$phone[$this->session]])
        ->and(PushSubscription::query()->pluck('session_id')->all())->toBe([$phone[$this->session]])
        ->and(DB::table('sessions')->whereIn('id', [$tablet[$this->session], $pc[$this->session]])->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'password.changed')->sole()->properties['other_devices'])
        ->toEqual(['sessions' => 2, 'push_subscriptions' => 1, 'remember_tokens' => 1]);

    // Session expirée : le nouveau cookie reconnecte le téléphone ; la tablette, plus.
    $this->travel(3)->hours();
    ($this->asDevice)($this->android, [$this->recaller => $recaller->getValue()]);
    ($this->me)()->assertOk();
    ($this->asDevice)($this->windows, $tablet);
    expectProblem(($this->me)(), 401, 'UNAUTHENTICATED');
});
