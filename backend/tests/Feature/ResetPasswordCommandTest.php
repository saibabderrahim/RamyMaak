<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;

// Réinitialisation de secours d'un mot de passe depuis le serveur (EF-AUTH-05).

it('resets a password from the server: temporary password, unlocked account, audit entry', function () {
    $admin = admin(['username' => 'a.benali', 'failed_logins' => 5, 'locked_until' => now()->addMinutes(10)]);
    $old = $admin->password;

    $this->artisan('ramymaak:reset-password', ['username' => 'A.Benali'])
        ->expectsOutputToContain('Mot de passe temporaire')
        ->assertSuccessful();

    $admin->refresh();
    expect($admin->password)->not->toBe($old)
        ->and($admin->must_change_password)->toBeTrue()
        ->and($admin->failed_logins)->toBe(0)
        ->and($admin->locked_until)->toBeNull()
        ->and(AuditLog::query()->where('log_name', 'auth')->where('event', 'password.reset')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

it('gives a temporary password that signs in and must then be changed', function () {
    admin(['username' => 'boss']);

    Artisan::call('ramymaak:reset-password', ['username' => 'boss']);
    preg_match('/Mot de passe temporaire : (\S+)/', Artisan::output(), $match);

    $this->postJson('/api/v1/auth/login', ['username' => 'boss', 'password' => $match[1]])
        ->assertOk()
        ->assertJsonPath('must_change_password', true);
});

it('fails for an unknown username', function () {
    $this->artisan('ramymaak:reset-password', ['username' => 'nobody'])->assertFailed();
});
