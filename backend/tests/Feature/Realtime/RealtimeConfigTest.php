<?php

use App\Domain\Access\Role;

// GET /auth/me : clé publique Reverb pour la connexion temps réel, ou null
// (rafraîchissement toutes les 30 s, §9.6 ; contrat du lot 3, §6).

it('gives the public Reverb key when realtime is configured', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'ramymaak-public-key',
        'broadcasting.connections.reverb.secret' => 'ramymaak-secret-value',
    ]);

    $this->actingAs(userWithRole(Role::Regional))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('realtime', ['key' => 'ramymaak-public-key'])
        // Jamais le secret.
        ->assertDontSee('ramymaak-secret-value');
});

it('answers null without Reverb or without a key', function (array $settings) {
    config($settings);

    $this->actingAs(userWithRole(Role::Client))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('realtime', null);
})->with([
    'broadcasting disabled' => [['broadcasting.default' => 'null', 'broadcasting.connections.reverb.key' => 'ramymaak-public-key']],
    'reverb without key' => [['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]],
    'other broadcaster' => [['broadcasting.default' => 'log', 'broadcasting.connections.reverb.key' => 'ramymaak-public-key']],
]);
