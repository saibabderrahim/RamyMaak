<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

function changePassword(string $new, ?string $confirmation = null, string $current = UserFactory::PASSWORD): TestResponse
{
    return test()->putJson('/api/v1/auth/password', [
        'current_password' => $current,
        'password' => $new,
        'password_confirmation' => $confirmation ?? $new,
    ]);
}

it('forces a password change before anything else after a reset', function () {
    $user = userWithRole(Role::Admin, ['username' => 'boss', 'must_change_password' => true]);

    $this->postJson('/api/v1/auth/login', ['username' => 'boss', 'password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertJsonPath('must_change_password', true);

    expectProblem($this->getJson('/api/v1/admin/users'), 403, 'PASSWORD_CHANGE_REQUIRED');
    $this->getJson('/api/v1/auth/me')->assertOk();

    changePassword('Nouveau-Mot-2026x')->assertOk()->assertJsonPath('must_change_password', false);

    $this->getJson('/api/v1/admin/users')->assertOk();
    expect(Hash::check('Nouveau-Mot-2026x', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->password_changed_at)->not->toBeNull();
});

it('requires 8 characters for clients and regionals, 12 for back-office staff', function (Role $role, string $password, bool $accepted) {
    $this->actingAs(userWithRole($role, ['username' => 'someone']));

    $response = changePassword($password);

    $accepted ? $response->assertOk() : $response->assertJsonValidationErrors('password');
})->with([
    'client, 8 chars' => [Role::Client, 'kiwi4567', true],
    'client, 7 chars' => [Role::Client, 'kiwi456', false],
    'regional, 8 chars' => [Role::Regional, 'kiwi4567', true],
    'adv, 11 chars' => [Role::Adv, 'kiwi4567abc', false],
    'adv, 12 chars' => [Role::Adv, 'kiwi4567abcd', true],
    'admin, 11 chars' => [Role::Admin, 'kiwi4567abc', false],
    'admin, 12 chars' => [Role::Admin, 'kiwi4567abcd', true],
]);

it('requires letters and digits', function (string $password) {
    $this->actingAs(userWithRole(Role::Client));

    changePassword($password)->assertJsonValidationErrors('password');
})->with(['onlyletters', '1234567890']);

it('refuses common passwords whatever their case', function (string $password) {
    $this->actingAs(userWithRole(Role::Client));

    $response = changePassword($password);

    $response->assertJsonValidationErrors('password');
    expect($response->json('errors.password'))->toContain(__('passwords.common'));
})->with(['azerty123', 'Password123', 'ALGERIE2026']);

it('refuses a password containing the username', function () {
    $this->actingAs(userWithRole(Role::Client, ['username' => 'c0012']));

    changePassword('maison-C0012')->assertJsonValidationErrors('password');
});

it('checks the current password and the confirmation', function () {
    $this->actingAs(userWithRole(Role::Client));

    changePassword('kiwi4567', current: 'wrong')->assertJsonValidationErrors('current_password');
    changePassword('kiwi4567', 'kiwi4568')->assertJsonValidationErrors('password');
});

it('refuses to keep the same password', function () {
    $this->actingAs(userWithRole(Role::Client));

    changePassword(UserFactory::PASSWORD)->assertJsonValidationErrors('password');
});

it('journals the change without the password', function () {
    $user = userWithRole(Role::Client);
    $this->actingAs($user);

    changePassword('kiwi4567')->assertOk();

    $entry = AuditLog::query()->where('event', 'password.changed')->sole();
    expect($entry->subject_id)->toBe($user->id)
        ->and($entry->toJson())->not->toContain('kiwi4567');
});

it('tells the PWA which minimum length applies to the user', function (Role $role, int $length) {
    $this->actingAs(userWithRole($role));

    $this->getJson('/api/v1/auth/me')->assertJsonPath('password_min_length', $length);
})->with([
    [Role::Client, 8],
    [Role::Regional, 8],
    [Role::Adv, 12],
    [Role::Admin, 12],
]);
