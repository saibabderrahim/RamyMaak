<?php

use App\Domain\Access\Role;
use App\Models\User;

it('creates the first admin with a temporary password to change at first login', function () {
    $this->artisan('ramymaak:create-admin', ['username' => 'A.Benali', '--name' => 'Amina Benali'])
        ->expectsOutputToContain('Mot de passe temporaire')
        ->assertSuccessful();

    $user = User::query()->where('username', 'a.benali')->sole();
    expect($user->name)->toBe('Amina Benali')
        ->and($user->hasAppRole(Role::Admin))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();
});

it('refuses an existing or malformed username', function () {
    admin(['username' => 'boss']);

    $this->artisan('ramymaak:create-admin', ['username' => 'boss'])->assertFailed();
    $this->artisan('ramymaak:create-admin', ['username' => 'a b'])->assertFailed();
});
