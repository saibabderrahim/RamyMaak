<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = admin(['username' => 'boss']);
});

it('is reserved to the Admin role', function (Role $role) {
    $this->actingAs(userWithRole($role));
    $target = userWithRole(Role::Client);

    expectProblem($this->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');
    expectProblem($this->postJson('/api/v1/admin/users', ['username' => 'x', 'name' => 'x', 'role' => 'client']), 403, 'FORBIDDEN');
    expectProblem($this->getJson("/api/v1/admin/users/{$target->id}"), 403, 'FORBIDDEN');
    expectProblem($this->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'x']), 403, 'FORBIDDEN');
})->with([Role::Adv, Role::Regional, Role::Client]);

it('lists users with filters, search, sort and pagination', function () {
    $this->actingAs($this->admin);
    userWithRole(Role::Client, ['username' => 'c0012', 'name' => 'Superette El Baraka']);
    userWithRole(Role::Client, ['username' => 'c0047', 'name' => 'Grossiste Amine', 'is_active' => false]);
    userWithRole(Role::Regional, ['username' => 'k.saidi', 'name' => 'Karim Saïdi']);

    $this->getJson('/api/v1/admin/users?filter[role]=client&sort=-username')
        ->assertOk()
        ->assertJsonPath('data.*.username', ['c0047', 'c0012'])
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/v1/admin/users?filter[search]=baraka')
        ->assertJsonPath('data.*.username', ['c0012']);

    $this->getJson('/api/v1/admin/users?filter[is_active]=0')
        ->assertJsonPath('data.*.username', ['c0047']);

    $this->getJson('/api/v1/admin/users?per_page=2')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 4);

    $this->getJson('/api/v1/admin/users?per_page=500')->assertJsonPath('meta.per_page', 100);
});

it('rejects unknown filters, sorts and roles', function () {
    $this->actingAs($this->admin);

    expectProblem($this->getJson('/api/v1/admin/users?filter[password]=x'), 422, 'VALIDATION_FAILED');
    expectProblem($this->getJson('/api/v1/admin/users?sort=password'), 422, 'VALIDATION_FAILED');
    expectProblem($this->getJson('/api/v1/admin/users?filter[role]=superuser'), 422, 'VALIDATION_FAILED');
});

it('creates an account with a one-time temporary password', function () {
    $this->actingAs($this->admin);
    $client = Client::factory()->create(['code' => 'C0012']);

    $response = $this->postJson('/api/v1/admin/users', [
        'username' => ' C0012 ',
        'name' => 'Superette El Baraka',
        'phone' => '0550 12 34 56',
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.username', 'c0012')
        ->assertJsonPath('user.role', 'client')
        ->assertJsonPath('user.client_id', $client->id)
        ->assertJsonPath('user.must_change_password', true);

    $temporary = $response->json('temporary_password');
    $user = User::query()->where('username', 'c0012')->sole();
    expect($temporary)->toMatch('/^[A-Za-z2-9]{10}$/')
        ->and(Hash::check($temporary, $user->password))->toBeTrue()
        ->and($user->hasAppRole(Role::Client))->toBeTrue();

    // Le mot de passe temporaire permet la connexion, puis impose le changement.
    forgetGuards();
    $this->postJson('/api/v1/auth/login', ['username' => 'c0012', 'password' => $temporary])
        ->assertOk()
        ->assertJsonPath('must_change_password', true);
});

it('validates account creation', function (array $payload, string $field) {
    $this->actingAs($this->admin);
    userWithRole(Role::Client, ['username' => 'c0012']);

    $this->postJson('/api/v1/admin/users', [
        'username' => 'new.user',
        'name' => 'Nouvel utilisateur',
        'role' => 'adv',
        ...$payload,
    ])->assertJsonValidationErrors($field);
})->with([
    'duplicate username' => [['username' => 'C0012'], 'username'],
    'username with spaces' => [['username' => 'jean dupont'], 'username'],
    'unknown role' => [['role' => 'superuser'], 'role'],
    'client link on staff' => [['client_id' => 3], 'client_id'],
    'invalid email' => [['email' => 'pas-un-email'], 'email'],
    'missing name' => [['name' => ''], 'name'],
]);

it('updates an account and journals the changes', function () {
    $this->actingAs($this->admin);
    $user = userWithRole(Role::Regional, ['name' => 'Ancien nom']);

    $this->patchJson("/api/v1/admin/users/{$user->id}", ['name' => 'Nouveau nom', 'role' => 'adv'])
        ->assertOk()
        ->assertJsonPath('name', 'Nouveau nom')
        ->assertJsonPath('role', 'adv');

    expect($user->fresh()->hasAppRole(Role::Adv))->toBeTrue()
        ->and($user->fresh()->getRoleNames()->all())->toBe(['adv']);

    $logs = AuditLog::query()->where('subject_type', 'user')->where('subject_id', $user->id)->get()->keyBy('event');
    expect($logs['updated']->description)->toBe('Compte modifié')
        ->and($logs['updated']->properties['old']['name'])->toBe('Ancien nom')
        ->and($logs['updated']->properties['attributes']['name'])->toBe('Nouveau nom')
        ->and($logs['updated']->causer_id)->toBe($this->admin->id)
        ->and($logs['role.changed']->properties['old']['role'])->toBe('regional')
        ->and($logs['role.changed']->properties['attributes']['role'])->toBe('adv');
});

it('detaches the client when an account leaves the client role', function () {
    $this->actingAs($this->admin);
    $user = userWithRole(Role::Client, ['client_id' => Client::factory()->create()->id]);

    $this->patchJson("/api/v1/admin/users/{$user->id}", ['role' => 'regional'])
        ->assertOk()
        ->assertJsonPath('client_id', null);
});

it('revokes every session when an account is deactivated', function () {
    $this->actingAs($this->admin);
    $user = userWithRole(Role::Client);
    DB::table('sessions')->insert([
        'id' => 'phone-session',
        'user_id' => $user->id,
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->patchJson("/api/v1/admin/users/{$user->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false);

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('unlocks a locked account', function () {
    $this->actingAs($this->admin);
    $user = userWithRole(Role::Client, []);
    $user->forceFill(['locked_until' => now()->addMinutes(10), 'failed_logins' => 3])->save();

    $this->patchJson("/api/v1/admin/users/{$user->id}", ['unlock' => true])
        ->assertOk()
        ->assertJsonPath('is_locked', false);

    expect($user->fresh())->failed_logins->toBe(0)->locked_until->toBeNull();
});

it('prevents an admin from demoting or deactivating themselves', function (array $payload, string $field) {
    $this->actingAs($this->admin);
    admin();

    $this->patchJson("/api/v1/admin/users/{$this->admin->id}", $payload)->assertJsonValidationErrors($field);
})->with([
    'role change' => [['role' => 'adv'], 'role'],
    'deactivation' => [['is_active' => false], 'is_active'],
]);

it('keeps at least one active admin, even when another role was granted account management', function () {
    // Un rôle peut être ajusté sans modifier le code (§3.2) : ici l'ADV reçoit la gestion des comptes.
    Spatie\Permission\Models\Role::findByName('adv')->givePermissionTo('user.manage');
    $this->actingAs(userWithRole(Role::Adv));

    $this->patchJson("/api/v1/admin/users/{$this->admin->id}", ['is_active' => false])
        ->assertJsonValidationErrors(['is_active' => __('users.last_admin')]);
    $this->patchJson("/api/v1/admin/users/{$this->admin->id}", ['role' => 'adv'])
        ->assertJsonValidationErrors(['role' => __('users.last_admin')]);

    $second = admin();
    $this->patchJson("/api/v1/admin/users/{$second->id}", ['is_active' => false])->assertOk();
});

it('lets an inactive admin be changed freely', function () {
    $this->actingAs($this->admin);
    $inactiveAdmin = admin(['is_active' => false]);

    $this->patchJson("/api/v1/admin/users/{$inactiveAdmin->id}", ['role' => 'client'])->assertOk();
});

it('resets a password: temporary password, forced change, sessions revoked, lock cleared', function () {
    $this->actingAs($this->admin);
    $user = userWithRole(Role::Client, ['username' => 'c0012']);
    $user->forceFill(['locked_until' => now()->addMinutes(10)])->save();
    DB::table('sessions')->insert([
        'id' => 'phone-session',
        'user_id' => $user->id,
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $response = $this->postJson("/api/v1/admin/users/{$user->id}/reset-password")->assertOk();

    $user->refresh();
    expect(Hash::check($response->json('temporary_password'), $user->password))->toBeTrue()
        ->and(Hash::check(UserFactory::PASSWORD, $user->password))->toBeFalse()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->isLocked())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    $entry = AuditLog::query()->where('event', 'password.reset')->sole();
    expect($entry->subject_id)->toBe($user->id)
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->toJson())->not->toContain($response->json('temporary_password'));
});

it('does not let a regional reset accounts outside their perimeter', function () {
    $this->actingAs(userWithRole(Role::Regional));

    expectProblem($this->postJson('/api/v1/admin/users/'.userWithRole(Role::Client)->id.'/reset-password'), 403, 'FORBIDDEN');
    expectProblem($this->postJson('/api/v1/admin/users/'.userWithRole(Role::Adv)->id.'/reset-password'), 403, 'FORBIDDEN');
});

it('returns a problem document for unknown users', function () {
    $this->actingAs($this->admin);

    expectProblem($this->getJson('/api/v1/admin/users/999999'), 404, 'NOT_FOUND');
});
