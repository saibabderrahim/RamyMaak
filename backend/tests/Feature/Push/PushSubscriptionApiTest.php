<?php

use App\Domain\Access\Role;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Push\PushTesting;

// Abonnements push de l'appareil et fin de session (contrat du lot 8, §2.1,
// §2.2 ; I-103, I-104).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    $this->body = fn (array $overrides = []) => array_replace_recursive([
        'endpoint' => PushTesting::ENDPOINT,
        'keys' => ['p256dh' => PushTesting::P256DH, 'auth' => PushTesting::AUTH],
        'content_encoding' => 'aes128gcm',
    ], $overrides);
    // Connexion réelle, puis cookie de session renvoyé comme le ferait le
    // navigateur : l'abonnement est rattaché à la session de l'appareil.
    $this->signIn = function (User $user): string {
        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => UserFactory::PASSWORD])->assertOk();
        $sessionId = (string) DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->value('id');
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);

        return $sessionId;
    };
});

it('registers the device of any role, attached to the user, the session and the browser', function (Role $role) {
    $user = userWithRole($role);
    $sessionId = ($this->signIn)($user);

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 13; Pixel 7) Chrome/129.0 Mobile Safari/537.36')
        ->postJson('/api/v1/push-subscriptions', ($this->body)())
        ->assertCreated()
        ->assertExactJson(['endpoint' => PushTesting::ENDPOINT, 'created_at' => now()->toIso8601String()]);

    $subscription = PushSubscription::query()->sole();
    expect($subscription->user_id)->toBe($user->id)
        ->and($subscription->session_id)->toBe($sessionId)
        ->and($subscription->public_key)->toBe(PushTesting::P256DH)
        ->and($subscription->auth_token)->toBe(PushTesting::AUTH)
        ->and($subscription->content_encoding)->toBe('aes128gcm')
        ->and($subscription->user_agent)->toBe('Mozilla/5.0 (Linux; Android 13; Pixel 7) Chrome/129.0 Mobile Safari/537.36')
        ->and($subscription->failures)->toBe(0)
        ->and($subscription->last_used_at->eq(now()))->toBeTrue();
})->with([Role::Client, Role::Regional, Role::Adv, Role::Admin]);

it('updates the subscription sent again at each opening of the PWA', function () {
    $user = userWithRole(Role::Client);
    $this->actingAs($user);
    $this->postJson('/api/v1/push-subscriptions', ($this->body)(['content_encoding' => null]))->assertCreated();
    $createdAt = now()->toIso8601String();
    PushSubscription::query()->update(['failures' => 2, 'session_id' => 'old-session']);

    $this->travel(2)->days();
    $this->withHeader('User-Agent', str_repeat('a', 300))
        ->postJson('/api/v1/push-subscriptions', ($this->body)(['keys' => ['auth' => 'AAAAAAAAAAAAAAAAAAAAAA'], 'content_encoding' => 'aesgcm']))
        ->assertOk()
        ->assertExactJson(['endpoint' => PushTesting::ENDPOINT, 'created_at' => $createdAt]);

    $subscription = PushSubscription::query()->sole();
    expect($subscription->auth_token)->toBe('AAAAAAAAAAAAAAAAAAAAAA')
        ->and($subscription->content_encoding)->toBe('aesgcm')
        ->and($subscription->failures)->toBe(0)
        ->and($subscription->session_id)->not->toBe('old-session')
        ->and($subscription->user_agent)->toHaveLength(255)
        ->and($subscription->last_used_at->eq(now()))->toBeTrue();
});

it('defaults the content encoding to aes128gcm', function () {
    $this->actingAs(userWithRole(Role::Client));
    $body = ($this->body)();
    unset($body['content_encoding']);

    $this->postJson('/api/v1/push-subscriptions', $body)->assertCreated();

    expect(PushSubscription::query()->sole()->content_encoding)->toBe('aes128gcm');
});

it('moves a shared device to the current account', function () {
    $previous = userWithRole(Role::Client);
    $subscription = PushSubscription::factory()->create(['user_id' => $previous->id, 'endpoint' => PushTesting::ENDPOINT]);
    $current = userWithRole(Role::Client);
    $this->actingAs($current);

    $this->postJson('/api/v1/push-subscriptions', ($this->body)())->assertOk();

    expect(PushSubscription::query()->sole())
        ->id->toBe($subscription->id)
        ->user_id->toBe($current->id);
});

it('accepts the endpoints of the known push services', function (string $endpoint) {
    $this->actingAs(userWithRole(Role::Client));

    $this->postJson('/api/v1/push-subscriptions', ($this->body)(['endpoint' => $endpoint]))->assertCreated();
})->with([
    'Chrome' => 'https://fcm.googleapis.com/fcm/send/abc:APA91b-xyz',
    'Chrome (ancien)' => 'https://android.googleapis.com/gcm/send/abc',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABl',
    'Edge' => 'https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB%2bXyz',
    'Safari' => 'https://web.push.apple.com/QGuQyavXutnMH9MBh',
    'port 443' => 'https://fcm.googleapis.com:443/fcm/send/abc',
]);

it('validates the subscription', function (array $overrides, string $field) {
    $this->actingAs(userWithRole(Role::Client));

    $response = $this->postJson('/api/v1/push-subscriptions', ($this->body)($overrides));

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonValidationErrors($field);
    expect(PushSubscription::query()->exists())->toBeFalse();
})->with([
    'no endpoint' => [['endpoint' => null], 'endpoint'],
    'not a URL' => [['endpoint' => 'fcm.googleapis.com/fcm/send/abc'], 'endpoint'],
    'http' => [['endpoint' => 'http://fcm.googleapis.com/fcm/send/abc'], 'endpoint'],
    'unknown host' => [['endpoint' => 'https://push.example.com/send/abc'], 'endpoint'],
    'internal network' => [['endpoint' => 'https://10.0.0.5/send/abc'], 'endpoint'],
    'localhost' => [['endpoint' => 'https://localhost/send/abc'], 'endpoint'],
    'look-alike host' => [['endpoint' => 'https://fcm.googleapis.com.evil.dz/send/abc'], 'endpoint'],
    'credentials' => [['endpoint' => 'https://fcm.googleapis.com@evil.dz/send/abc'], 'endpoint'],
    'other port' => [['endpoint' => 'https://fcm.googleapis.com:8443/fcm/send/abc'], 'endpoint'],
    'bare wildcard domain' => [['endpoint' => 'https://push.apple.com/abc'], 'endpoint'],
    'too long' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 470)], 'endpoint'],
    'no keys' => [['keys' => null], 'keys'],
    'short p256dh' => [['keys' => ['p256dh' => substr(PushTesting::P256DH, 0, 40)]], 'keys.p256dh'],
    'p256dh not base64url' => [['keys' => ['p256dh' => strtr(PushTesting::P256DH, '-_', '+/').'!']], 'keys.p256dh'],
    'auth of 15 bytes' => [['keys' => ['auth' => 'tBHItJI5svbpez7KI4CC']], 'keys.auth'],
    'auth of 18 bytes' => [['keys' => ['auth' => PushTesting::AUTH.'AA']], 'keys.auth'],
    'no auth' => [['keys' => ['auth' => null]], 'keys.auth'],
    'unknown encoding' => [['content_encoding' => 'gzip'], 'content_encoding'],
]);

it('requires an authenticated user', function () {
    expectProblem($this->postJson('/api/v1/push-subscriptions', ($this->body)()), 401, 'UNAUTHENTICATED');
    expectProblem($this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => PushTesting::ENDPOINT]), 401, 'UNAUTHENTICATED');
});

it('limits the subscription requests to 20 per minute and per user', function () {
    $this->actingAs(userWithRole(Role::Client));

    foreach (range(1, 20) as $attempt) {
        $this->postJson('/api/v1/push-subscriptions', ($this->body)())->assertSuccessful();
    }

    expectProblem($this->postJson('/api/v1/push-subscriptions', ($this->body)()), 429, 'TOO_MANY_REQUESTS');
});

it('unsubscribes the device of the current account only', function () {
    $user = userWithRole(Role::Client);
    $other = userWithRole(Role::Client);
    $mine = PushSubscription::factory()->create(['user_id' => $user->id, 'endpoint' => PushTesting::ENDPOINT]);
    $theirs = PushSubscription::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user);

    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => PushTesting::ENDPOINT])->assertNoContent();
    // Inconnu ou d'un autre compte : 204, rien ne change.
    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => PushTesting::ENDPOINT])->assertNoContent();
    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => $theirs->endpoint])->assertNoContent();

    expect(PushSubscription::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(PushSubscription::query()->whereKey($theirs->id)->exists())->toBeTrue();
    expectProblem($this->deleteJson('/api/v1/push-subscriptions', []), 422, 'VALIDATION_FAILED');
});

it('removes the subscriptions of the device at logout, and only them', function () {
    $user = userWithRole(Role::Client);
    $sessionId = ($this->signIn)($user);
    $this->postJson('/api/v1/push-subscriptions', ($this->body)())->assertCreated();
    expect(PushSubscription::query()->sole()->session_id)->toBe($sessionId);
    $tablet = PushSubscription::factory()->create(['user_id' => $user->id, 'session_id' => 'tablet-session']);
    // Même identifiant de session rattaché à un autre compte : ne le concerne pas.
    $foreign = PushSubscription::factory()->create(['user_id' => userWithRole(Role::Client)->id, 'session_id' => $sessionId]);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(PushSubscription::query()->pluck('id')->sort()->values()->all())->toBe([$tablet->id, $foreign->id]);
});

it('removes every subscription of the user when every session ends', function (Closure $end) {
    $user = userWithRole(Role::Client, ['username' => 'c0012']);
    PushSubscription::factory()->count(2)->create(['user_id' => $user->id]);
    $other = PushSubscription::factory()->create(['user_id' => userWithRole(Role::Client)->id]);

    $end($user);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$other->id]);
})->with([
    'logout from every device' => function (User $user) {
        test()->postJson('/api/v1/auth/login', ['username' => 'c0012', 'password' => UserFactory::PASSWORD])->assertOk();
        test()->postJson('/api/v1/auth/logout-all')->assertNoContent();
    },
    'deactivation by the Admin' => function (User $user) {
        test()->actingAs(admin());
        test()->patchJson("/api/v1/admin/users/{$user->id}", ['is_active' => false])->assertOk();
    },
    'password reset by the Admin' => function (User $user) {
        test()->actingAs(admin());
        test()->postJson("/api/v1/admin/users/{$user->id}/reset-password")->assertOk();
    },
    'password reset from the server' => function (User $user) {
        test()->artisan('ramymaak:reset-password', ['username' => 'c0012'])->assertSuccessful();
    },
]);

it('gives the VAPID public key in /auth/me only with keys and push enabled', function () {
    $this->actingAs(userWithRole(Role::Client));

    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('push', null);

    PushTesting::configureVapid();
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('push', ['public_key' => PushTesting::publicKey()]);

    PushTesting::settings(['push_enabled' => false]);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('push', null);
});

it('lets the Admin read and switch push off in the settings', function () {
    $admin = admin();
    $this->actingAs($admin);

    $this->getJson('/api/v1/admin/settings')
        ->assertOk()
        ->assertJsonPath('notifications.push_enabled', true)
        ->assertJsonPath('notifications.quiet_start', '21:00')
        ->assertJsonPath('notifications.quiet_end', '06:00');

    $this->putJson('/api/v1/admin/settings', ['notifications' => ['push_enabled' => false]])
        ->assertOk()
        ->assertJsonPath('notifications.push_enabled', false);
    $this->getJson('/api/v1/admin/settings')->assertJsonPath('notifications.push_enabled', false);

    $response = $this->putJson('/api/v1/admin/settings', ['notifications' => ['push_enabled' => 'parfois']]);
    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonValidationErrors('notifications.push_enabled');
    expect($response->json('errors')['notifications.push_enabled'][0])->toContain('notifications push');
});

it('keeps the 10 most recently used devices of an account', function () {
    $user = userWithRole(Role::Client);
    $this->actingAs($user);
    $old = PushSubscription::factory()->count(10)->sequence(fn ($sequence) => ['last_used_at' => now()->subDays(10 - $sequence->index)])->create(['user_id' => $user->id]);
    $other = PushSubscription::factory()->create(['user_id' => userWithRole(Role::Client)->id, 'last_used_at' => now()->subYear()]);

    $this->postJson('/api/v1/push-subscriptions', ($this->body)())->assertCreated();

    expect(PushSubscription::query()->where('user_id', $user->id)->count())->toBe(10)
        // Le moins récemment utilisé est retiré ; ceux des autres comptes ne sont pas comptés.
        ->and(PushSubscription::query()->whereKey($old->first()->id)->exists())->toBeFalse()
        ->and(PushSubscription::query()->where('endpoint', PushTesting::ENDPOINT)->exists())->toBeTrue()
        ->and($other->fresh())->not->toBeNull();
});
