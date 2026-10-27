<?php

use App\Domain\Access\Role;
use App\Models\Client;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

// N-15 (I-111) : réinitialisation du mot de passe signalée au compte, dans
// l'application seulement.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:12 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:12', 'Africa/Algiers'));
    $this->received = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', 'ACCOUNT_SECURITY')
        ->orderBy('created_at')
        ->get();
});

it('tells the account its password was reset by the Admin, once per reset', function () {
    $user = userWithRole(Role::Regional, ['username' => 'reg']);
    $this->actingAs(admin());

    $this->postJson("/api/v1/admin/users/{$user->id}/reset-password")->assertOk();

    $notifications = ($this->received)($user);
    expect($notifications)->toHaveCount(1)
        ->and(collect($notifications[0]->data)->sortKeys()->all())->toBe(collect([
            'type' => 'ACCOUNT_SECURITY',
            'code' => 'N-15',
            'title' => 'Mot de passe réinitialisé',
            'body' => 'Votre mot de passe a été réinitialisé par l\'administrateur le 08/10/2026 à 09:12. Si vous n\'en avez pas fait la demande, prévenez-le.',
            'url' => '/profil',
        ])->sortKeys()->all())
        // Dans l'application seulement (contrat du lot 8, §2.3).
        ->and(NotificationType::AccountSecurity->pushable())->toBeFalse();

    // Nouvelle réinitialisation : nouvelle notification.
    $this->travel(5)->minutes();
    $this->postJson("/api/v1/admin/users/{$user->id}/reset-password")->assertOk();
    expect(($this->received)($user)->pluck('data.body')->last())->toContain('le 08/10/2026 à 09:17.')
        ->and(($this->received)($user))->toHaveCount(2);
});

it('names the regional when they reset a client account (EF-AUTH-05)', function () {
    $centre = Region::factory()->create();
    $regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $centre->id]);
    $account = userWithRole(Role::Client, ['client_id' => Client::factory()->create(['region_id' => $centre->id])->id]);
    $this->actingAs($regional);

    $this->postJson("/api/v1/admin/users/{$account->id}/reset-password")->assertOk();

    expect(($this->received)($account)->sole()->data['body'])
        ->toBe('Votre mot de passe a été réinitialisé par votre régional le 08/10/2026 à 09:12. Si vous n\'en avez pas fait la demande, prévenez l\'administrateur.')
        ->and(($this->received)($regional))->toBeEmpty();
});

it('sends nothing when the reset is refused', function () {
    $other = admin();
    $this->actingAs(userWithRole(Role::Adv));

    $this->postJson("/api/v1/admin/users/{$other->id}/reset-password")->assertForbidden();

    expect(($this->received)($other))->toBeEmpty();
});

it('tells the account its password was reset from the server (ramymaak:reset-password)', function () {
    $user = admin(['username' => 'boss']);

    $this->artisan('ramymaak:reset-password', ['username' => 'boss'])->assertSuccessful();

    $notification = ($this->received)($user)->sole();
    expect($notification->data['title'])->toBe('Mot de passe réinitialisé')
        ->and($notification->data['body'])->toBe('Votre mot de passe a été réinitialisé le 08/10/2026 à 09:12. Si vous n\'êtes pas à l\'origine de cette demande, prévenez l\'administrateur.')
        ->and($notification->data['url'])->toBe('/profil');
});
