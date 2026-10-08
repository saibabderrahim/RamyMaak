<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;

function login(string $username, string $password = UserFactory::PASSWORD, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password, ...$extra]);
}

// Critère de fin du lot 1 : connexion par rôle (§16).
it('logs in every role and returns its profile', function (Role $role) {
    $user = userWithRole($role, ['username' => 'user-'.$role->value]);

    login('user-'.$role->value)
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('role', $role->value)
        ->assertJsonPath('must_change_password', false)
        ->assertJsonStructure(['permissions', 'perimeter' => ['region_ids']]);

    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('username', 'user-'.$role->value);
    expect($user->fresh()->last_login_at)->not->toBeNull();
})->with(Role::cases());

it('treats the username case-insensitively', function () {
    userWithRole(Role::Client, ['username' => 'C0012']);

    login('  c0012 ')->assertOk()->assertJsonPath('username', 'c0012');
});

it('answers identically for a wrong password and an unknown username', function () {
    userWithRole(Role::Client, ['username' => 'c0012']);

    $wrongPassword = login('c0012', 'not-the-password');
    $unknownUser = login('c9999', 'not-the-password');

    expectProblem($wrongPassword, 401, 'INVALID_CREDENTIALS');
    expectProblem($unknownUser, 401, 'INVALID_CREDENTIALS');
    expect($wrongPassword->json('detail'))->toBe($unknownUser->json('detail'));
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('locks the account for 15 minutes after 5 consecutive failures', function () {
    $user = userWithRole(Role::Client, ['username' => 'c0012']);

    foreach (range(1, 5) as $attempt) {
        expectProblem(login('c0012', 'wrong'), 401, 'INVALID_CREDENTIALS');
    }

    // Même avec le bon mot de passe, le compte reste verrouillé.
    $locked = login('c0012');
    expectProblem($locked, 423, 'ACCOUNT_LOCKED');
    expect($locked->json('retry_after_minutes'))->toBe(15);
    expect($user->fresh()->isLocked())->toBeTrue();

    $this->travel(16)->minutes();
    login('c0012')->assertOk();
    expect($user->fresh())->failed_logins->toBe(0)->locked_until->toBeNull();
});

it('resets the failure counter after a successful login', function () {
    $user = userWithRole(Role::Client, ['username' => 'c0012']);

    foreach (range(1, 4) as $attempt) {
        login('c0012', 'wrong');
    }
    login('c0012')->assertOk();

    expect($user->fresh()->failed_logins)->toBe(0);
});

it('applies the same lockout to unknown usernames so that responses do not reveal existence', function () {
    foreach (range(1, 5) as $attempt) {
        expectProblem(login('ghost', 'wrong'), 401, 'INVALID_CREDENTIALS');
    }

    expectProblem(login('ghost', 'wrong'), 423, 'ACCOUNT_LOCKED');
});

it('refuses a deactivated account', function () {
    userWithRole(Role::Regional, ['username' => 'reg', 'is_active' => false]);

    expectProblem(login('reg'), 403, 'ACCOUNT_DISABLED');
    forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('limits login attempts per IP address', function () {
    foreach (range(1, 10) as $attempt) {
        login("unknown{$attempt}", 'wrong')->assertStatus(401);
    }

    expectProblem(login('unknown11', 'wrong'), 429, 'TOO_MANY_REQUESTS');
});

it('validates the login payload', function () {
    $response = $this->postJson('/api/v1/auth/login', ['username' => '']);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonValidationErrors(['username', 'password']);
});

it('journals successful and failed logins with IP and device', function () {
    $user = userWithRole(Role::Client, ['username' => 'c0012']);

    login('c0012', 'wrong');
    $this->withHeader('User-Agent', 'Pixel 6 / Chrome')->postJson('/api/v1/auth/login', [
        'username' => 'c0012',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $entries = AuditLog::query()->where('log_name', 'auth')->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['login.failed', 'login.succeeded'])
        ->and($entries[0]->properties['reason'])->toBe('bad_password')
        ->and($entries[1]->properties['ip'])->toBe('127.0.0.1')
        ->and($entries[1]->properties['user_agent'])->toBe('Pixel 6 / Chrome')
        ->and($entries[1]->subject_id)->toBe($user->id);
});

it('never stores the password in the audit log', function () {
    userWithRole(Role::Client, ['username' => 'c0012']);

    login('c0012', 'Wrong-Secret-42');
    login('c0012');

    expect(AuditLog::query()->get()->toJson())->not->toContain('Wrong-Secret-42')
        ->not->toContain(UserFactory::PASSWORD);
});

it('regenerates the session identifier on login', function () {
    userWithRole(Role::Client, ['username' => 'c0012']);
    $this->getJson('/sanctum/csrf-cookie');
    $before = session()->getId();

    login('c0012')->assertOk();

    expect(session()->getId())->not->toBe($before);
});

it('only lets clients and regionals stay signed in', function (Role $role, bool $remembered) {
    $user = userWithRole($role, ['username' => 'someone']);

    login('someone', extra: ['remember' => true])->assertOk();

    expect($user->fresh()->remember_token !== null)->toBe($remembered);
})->with([
    'client' => [Role::Client, true],
    'regional' => [Role::Regional, true],
    'adv' => [Role::Adv, false],
    'admin' => [Role::Admin, false],
]);

it('keeps the remember-me cookie for 30 days', function () {
    userWithRole(Role::Client, ['username' => 'c0012']);

    $response = login('c0012', extra: ['remember' => true]);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->getTimestamp() + 1);
});

it('does not expose the user model internals', function () {
    userWithRole(Role::Client, ['username' => 'c0012']);

    login('c0012')->assertOk()->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');
});

it('normalizes usernames when creating users', function () {
    expect(User::factory()->create(['username' => ' Mixed.Case '])->username)->toBe('mixed.case');
});
