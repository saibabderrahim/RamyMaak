<?php

use App\Domain\Access\Role;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'Africa/Algiers'));
    $this->user = userWithRole(Role::Client, ['username' => 'c0012']);
    $this->android = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
    $this->windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
    $this->session = config('session.cookie');
    $this->recaller = Auth::guard('web')->getRecallerName();
    $this->asDevice = function (string $userAgent, array $cookies = []): void {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('cookie')->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->withCredentials()->withHeader('User-Agent', $userAgent)->withCookies($cookies);
    };
    $this->signIn = function (string $userAgent, bool $remember = true, string $username = 'c0012', array $cookies = []): array {
        ($this->asDevice)($userAgent, $cookies);
        $response = $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => UserFactory::PASSWORD, 'remember' => $remember])->assertOk();
        $device = [$this->session => $response->getCookie($this->session)->getValue()];
        if (($recaller = $response->getCookie($this->recaller)) !== null) {
            $device[$this->recaller] = $recaller->getValue();
        }

        return $device;
    };
    $this->me = fn () => $this->getJson('/api/v1/auth/me');
});

it('RACE: two parallel requests after session expiry; revoking the device entry it uses leaves it signed back in', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);

    $this->travel(3)->hours();
    // Two concurrent requests from the phone carry the same (expired) session cookie + recaller.
    ($this->asDevice)($this->android, $phone);
    $s1 = ($this->me)()->assertOk()->getCookie($this->session)->getValue();
    ($this->asDevice)($this->android, $phone);
    $s2 = ($this->me)()->assertOk()->getCookie($this->session)->getValue();
    // Browser kept the response of the first request (arrived last).
    $kept = [$this->session => $s1, $this->recaller => $phone[$this->recaller]];

    $token = DB::table('remember_tokens')->where('user_agent', $this->android)->sole();
    dump(['s1' => $s1, 's2' => $s2, 'token_session' => $token->session_id]);

    // Tablet lists and revokes the phone entry whose session is the one the phone uses (s1).
    ($this->asDevice)($this->windows, [$this->session => ($this->signIn)($this->windows)[$this->session]]);
    $devices = collect($this->getJson('/api/v1/auth/sessions')->assertOk()->json('data'));
    dump($devices->map(fn ($d) => [$d['id'], $d['device'], $d['remembered'], $d['current']])->all());
    $s1Id = 's_'.substr(hash_hmac('sha256', $s1, (string) config('app.key')), 0, 12);
    $this->deleteJson('/api/v1/auth/sessions/'.$s1Id)->assertNoContent();

    // The phone comes back with its kept cookies: still signed in through its token.
    ($this->asDevice)($this->android, $kept);
    ($this->me)()->assertOk();
    expect(DB::table('remember_tokens')->where('user_agent', $this->android)->exists())->toBeTrue();
});

it('GHOST: self-service password change keeps other tokens listed as remembered though they cannot sign in', function () {
    $phone = ($this->signIn)($this->android);
    $tablet = ($this->signIn)($this->windows);
    ($this->asDevice)($this->windows, $tablet);
    $this->putJson('/api/v1/auth/password', ['current_password' => UserFactory::PASSWORD, 'password' => 'N0uveau-mot-de-passe!x', 'password_confirmation' => 'N0uveau-mot-de-passe!x'])->assertOk();
    dump(DB::table('remember_tokens')->count());

    $this->travel(3)->hours();
    ($this->asDevice)($this->android, $phone);
    $r = ($this->me)();
    dump($r->status());
    dump(DB::table('remember_tokens')->get(['id', 'user_agent', 'last_used_at'])->all());
    // The tablet itself (which changed the password) also loses its remember cookie.
    ($this->asDevice)($this->windows, $tablet);
    dump(['tablet after expiry' => ($this->me)()->status()]);
});
