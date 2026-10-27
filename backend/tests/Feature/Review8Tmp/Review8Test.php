<?php

use App\Domain\Access\Role;
use App\Domain\Push\FakePushTransport;
use App\Domain\Settings\Settings;
use App\Models\PushSubscription;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Push\PushTesting;

it('R1: logout after a remember-me recall leaves the push subscription', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'Africa/Algiers'));
    PushTesting::configureVapid();
    $user = userWithRole(Role::Client, ['username' => 'c0012']);
    $sessionCookie = config('session.cookie');
    $recallerName = Auth::guard('web')->getRecallerName();
    $ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
    $asDevice = function (array $cookies = []) use ($ua): void {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('cookie')->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->withCredentials()->withHeader('User-Agent', $ua)->withCookies($cookies);
    };

    $asDevice();
    $login = $this->postJson('/api/v1/auth/login', ['username' => 'c0012', 'password' => UserFactory::PASSWORD, 'remember' => true])->assertOk();
    $phone = [$sessionCookie => $login->getCookie($sessionCookie)->getValue(), $recallerName => $login->getCookie($recallerName)->getValue()];

    $asDevice($phone);
    $this->postJson('/api/v1/push-subscriptions', ['endpoint' => PushTesting::ENDPOINT, 'keys' => ['p256dh' => PushTesting::P256DH, 'auth' => PushTesting::AUTH]])->assertCreated();
    expect(PushSubscription::query()->sole()->session_id)->toBe($phone[$sessionCookie]);

    // Session expired; the PWA stays open, next API call reconnects through the cookie.
    $this->travel(3)->hours();
    $asDevice($phone);
    $me = $this->getJson('/api/v1/auth/me')->assertOk();
    $newSession = $me->getCookie($sessionCookie)->getValue();
    expect($newSession)->not->toBe($phone[$sessionCookie]);

    // « Me déconnecter » from this device.
    $asDevice([$sessionCookie => $newSession, $recallerName => $phone[$recallerName]]);
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    // I-103 expects 0; prints what remains.
    fwrite(STDERR, json_encode(['remaining_subscriptions' => PushSubscription::query()->where('user_id', $user->id)->count()]).PHP_EOL);
    expect(PushSubscription::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('R2: the cached push channel keeps stale quiet hours in a long-lived worker', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 22:00', 'Africa/Algiers'));
    PushTesting::configureVapid();
    $push = FakePushTransport::install();
    $user = userWithRole(Role::Regional);
    PushSubscription::factory()->create(['user_id' => $user->id]);

    // Job 1 (worker): default quiet hours 21:00 → 06:00: nothing.
    PushTesting::notification(NotificationType::QuotasPublished, 'a')->sendOnce([$user]);
    expect($push->sent)->toHaveCount(0);

    // Admin (web process) moves the quiet hours to 23:00 → 06:00.
    app()->forgetScopedInstances();
    app(Settings::class)->update(['notifications' => ['quiet_start' => '23:00', 'quiet_end' => '06:00']], null);

    // Job 2 in the same worker (scoped instances reset as Worker does).
    app()->forgetScopedInstances();
    PushTesting::notification(NotificationType::QuotasPublished, 'b')->sendOnce([$user]);
    $afterJob2 = count($push->sent);

    // Control: a fresh process (channel drivers recreated).
    app()->forgetScopedInstances();
    app(ChannelManager::class)->forgetDrivers();
    PushTesting::notification(NotificationType::QuotasUpdated, 'c')->sendOnce([$user]);
    fwrite(STDERR, json_encode(['sent_after_job2' => $afterJob2, 'sent_after_fresh' => count($push->sent)]).PHP_EOL);
    expect($afterJob2)->toBe(1);
});
