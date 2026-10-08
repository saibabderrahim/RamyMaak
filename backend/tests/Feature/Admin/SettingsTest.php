<?php

use App\Domain\Access\Role;
use App\Domain\Settings\Settings;
use App\Models\AuditLog;
use Database\Factories\UserFactory;

it('is reserved to the Admin role', function (Role $role) {
    $this->actingAs(userWithRole($role));

    expectProblem($this->getJson('/api/v1/admin/settings'), 403, 'FORBIDDEN');
    expectProblem($this->putJson('/api/v1/admin/settings', ['orders' => ['max_per_day' => 2]]), 403, 'FORBIDDEN');
})->with([Role::Adv, Role::Regional, Role::Client]);

it('returns the defaults of the specification', function () {
    $this->actingAs(admin());

    $this->getJson('/api/v1/admin/settings')
        ->assertOk()
        ->assertJsonPath('cutoffs', [
            'distribution' => '10:00',
            'ordering' => '13:00',
            'validation' => '15:00',
            'closing' => '23:00',
            'validation_blocking' => false,
        ])
        ->assertJsonPath('calendar.working_days', [0, 1, 2, 3, 4])
        ->assertJsonPath('limits.no_limit_policy', 'FREE')
        ->assertJsonPath('orders.max_per_day', 1)
        ->assertJsonPath('orders.min_amount', null)
        ->assertJsonPath('passwords.min_length', 8)
        ->assertJsonPath('passwords.min_length_staff', 12)
        ->assertJsonPath('auth.max_failed_logins', 5)
        ->assertJsonPath('auth.lockout_minutes', 15);
});

it('updates part of the settings and journals old and new values', function () {
    $admin = admin();
    $this->actingAs($admin);

    $this->putJson('/api/v1/admin/settings', [
        'cutoffs' => ['ordering' => '13:30'],
        'orders' => ['max_per_day' => '2', 'min_amount' => 15000],
        'calendar' => ['working_days' => [4, 0, 1]],
    ])
        ->assertOk()
        ->assertJsonPath('cutoffs.ordering', '13:30')
        ->assertJsonPath('cutoffs.validation', '15:00')
        ->assertJsonPath('orders.max_per_day', 2)
        ->assertJsonPath('orders.min_amount', 15000)
        ->assertJsonPath('calendar.working_days', [0, 1, 4]);

    expect(app(Settings::class)->get('cutoffs.ordering'))->toBe('13:30');

    $entry = AuditLog::query()->where('log_name', 'settings')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['old']['cutoffs.ordering'])->toBe('13:00')
        ->and($entry->properties['attributes']['cutoffs.ordering'])->toBe('13:30');
});

it('does not journal an update that changes nothing', function () {
    $this->actingAs(admin());

    $this->putJson('/api/v1/admin/settings', ['orders' => ['max_per_day' => 1]])->assertOk();

    expect(AuditLog::query()->where('log_name', 'settings')->exists())->toBeFalse();
});

it('validates values', function (array $payload, string $field) {
    $this->actingAs(admin());

    $this->putJson('/api/v1/admin/settings', $payload)->assertJsonValidationErrors($field);
})->with([
    'bad time' => [['cutoffs' => ['ordering' => '25:00']], 'cutoffs.ordering'],
    'cutoffs out of order' => [['cutoffs' => ['ordering' => '09:00']], 'cutoffs.ordering'],
    'unknown policy' => [['limits' => ['no_limit_policy' => 'SOMETIMES']], 'limits.no_limit_policy'],
    'zero orders' => [['orders' => ['max_per_day' => 0]], 'orders.max_per_day'],
    'bad weekday' => [['calendar' => ['working_days' => [7]]], 'calendar.working_days.0'],
    'duplicate weekday' => [['calendar' => ['working_days' => [1, 1]]], 'calendar.working_days.0'],
    'no working day' => [['calendar' => ['working_days' => []]], 'calendar.working_days'],
    'short password' => [['passwords' => ['min_length' => 6]], 'passwords.min_length'],
    'unknown key' => [['orders' => ['discount' => 5]], 'orders.discount'],
    'null required value' => [['auth' => ['lockout_minutes' => null]], 'auth.lockout_minutes'],
]);

it('drives the password policy and the lockout', function () {
    $this->actingAs(admin());
    $this->putJson('/api/v1/admin/settings', [
        'passwords' => ['min_length' => 10],
        'auth' => ['max_failed_logins' => 3],
    ])->assertOk();

    $client = userWithRole(Role::Client, ['username' => 'c0012']);
    $this->actingAs($client);
    $this->putJson('/api/v1/auth/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'kiwi45678',
        'password_confirmation' => 'kiwi45678',
    ])->assertJsonValidationErrors('password');

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['username' => 'c0012', 'password' => 'wrong']);
    }
    expect($client->fresh()->isLocked())->toBeTrue();
});
