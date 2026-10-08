<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

function signIn(string $username, bool $remember = false): void
{
    test()->postJson('/api/v1/auth/login', [
        'username' => $username,
        'password' => UserFactory::PASSWORD,
        'remember' => $remember,
    ])->assertOk();
}

it('expires a back-office session after 2 hours of inactivity', function (Role $role) {
    userWithRole($role, ['username' => 'staff']);
    signIn('staff');

    $this->travel(119)->minutes();
    $this->getJson('/api/v1/auth/me')->assertOk();

    $this->travel(121)->minutes();
    $response = $this->getJson('/api/v1/auth/me');
    expectProblem($response, 401, 'UNAUTHENTICATED');
    expect($response->json('detail'))->toContain('expiré');

    forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
})->with([Role::Admin, Role::Adv]);

it('ends a back-office session after 12 hours even when active', function () {
    userWithRole(Role::Adv, ['username' => 'adv']);
    signIn('adv');

    foreach (range(1, 12) as $hour) {
        $this->travel(59)->minutes();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    $this->travel(61)->minutes();
    expectProblem($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
});

it('does not apply the back-office limits to clients and regionals', function (Role $role) {
    userWithRole($role, ['username' => 'field']);
    signIn('field');

    foreach (range(1, 14) as $hour) {
        $this->travel(1)->hours();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }
})->with([Role::Client, Role::Regional]);

it('logs out the current device', function () {
    $user = userWithRole(Role::Client, ['username' => 'c0012']);
    signIn('c0012');

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    expect(AuditLog::query()->where('event', 'logout')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('logs out every device of the user and only theirs', function () {
    $user = userWithRole(Role::Client, ['username' => 'c0012']);
    $other = userWithRole(Role::Client, ['username' => 'c0047']);
    foreach ([[$user, 'phone'], [$user, 'tablet'], [$other, 'phone']] as [$owner, $id]) {
        DB::table('sessions')->insert([
            'id' => $owner->username.'-'.$id,
            'user_id' => $owner->id,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }
    signIn('c0012', remember: true);
    $token = $user->fresh()->remember_token;

    $this->postJson('/api/v1/auth/logout-all')->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($user->fresh()->remember_token)->not->toBe($token);
    forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('cuts access immediately when the account is deactivated', function () {
    $user = userWithRole(Role::Regional, ['username' => 'reg']);
    signIn('reg');

    $user->forceFill(['is_active' => false])->save();
    forgetGuards();

    expectProblem($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
});

it('rejects unauthenticated requests with a problem document', function () {
    expectProblem($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
});
