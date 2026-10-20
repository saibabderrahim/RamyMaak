<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Models\AuditLog;
use App\Models\ReasonCode;
use App\Models\User;
use Spatie\Permission\Models\Role as SpatieRole;

// Motifs de l'Admin (EF-ADM-05, I-82 ; contrat du lot 6, §2 et §4) :
// GET /admin/reason-codes, POST, PATCH /admin/reason-codes/{id}. Droit
// settings.manage (403 avant 404 avant 422) ; code et type immuables ; pas
// de suppression, on désactive ; journal `reasons`.

beforeEach(function () {
    $this->admin = admin(['name' => 'Nadia Admin']);
    $this->users = fn (string $role): User => match ($role) {
        'admin' => $this->admin,
        'regional' => userWithRole(Role::Regional),
        'adv' => userWithRole(Role::Adv),
        'client' => userWithRole(Role::Client),
        'none' => User::factory()->create(),
    };
    $this->as = fn (string $role = 'admin') => $this->actingAs(($this->users)($role));
    $this->find = fn (string $type, string $code): ReasonCode => ReasonCode::query()->where('type', $type)->where('code', $code)->sole();
    $this->create = fn (array $body = []) => ($this->as)()->postJson('/api/v1/admin/reason-codes', [
        'type' => 'REJECTION', 'code' => 'RETARD_CAMION', 'label' => 'Retard du camion', ...$body,
    ]);
    // Erreurs attendues : champ → message, ou null quand le message est celui du cadre de validation (seul le champ compte).
    $this->assertErrors = function ($response, array $errors): void {
        $actual = $response->json('errors');
        expect(array_keys($actual))->toBe(array_keys($errors));
        foreach ($errors as $field => $message) {
            if ($message !== null) {
                expect($actual[$field])->toBe([$message]);
            }
        }
    };
    $this->patch = fn (ReasonCode|int|string $reason, array $body, string $role = 'admin') => ($this->as)($role)->patchJson(
        '/api/v1/admin/reason-codes/'.($reason instanceof ReasonCode ? $reason->id : $reason),
        $body,
    );
});

describe('rights', function () {
    it('refuses with 403 the regional, the ADV, a client account and an account without role, with no effect', function (string $role) {
        $reason = ($this->find)('REJECTION', 'DOUBLON');
        $count = ReasonCode::query()->count();

        $this->actingAs(($this->users)($role));
        expectProblem($this->getJson('/api/v1/admin/reason-codes'), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/admin/reason-codes', ['type' => 'REJECTION', 'code' => 'NOUVEAU', 'label' => 'Nouveau']), 403, 'FORBIDDEN');
        expectProblem($this->patchJson("/api/v1/admin/reason-codes/{$reason->id}", ['label' => 'Piraté']), 403, 'FORBIDDEN');

        expect(ReasonCode::query()->count())->toBe($count)
            ->and($reason->fresh()->label)->toBe('Commande en double');
    })->with(['regional', 'adv', 'client', 'none']);

    it('requires an authenticated session', function () {
        expectProblem($this->getJson('/api/v1/admin/reason-codes'), 401, 'UNAUTHENTICATED');
        expectProblem($this->postJson('/api/v1/admin/reason-codes', []), 401, 'UNAUTHENTICATED');
        expectProblem($this->patchJson('/api/v1/admin/reason-codes/1', []), 401, 'UNAUTHENTICATED');
    });

    it('follows the settings.manage permission of the Admin role, not only the role', function () {
        SpatieRole::findByName(Role::Admin->value)->revokePermissionTo(Permission::SettingsManage->value);
        $this->actingAs($this->admin);

        expectProblem($this->getJson('/api/v1/admin/reason-codes'), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/admin/reason-codes', ['type' => 'REJECTION', 'code' => 'NOUVEAU', 'label' => 'Nouveau']), 403, 'FORBIDDEN');
    });

    it('checks the rights before the existence of the reason and the format of the body (403 before 404 before 422)', function () {
        expectProblem(($this->patch)(999_999, ['label' => ''], 'adv'), 403, 'FORBIDDEN');
        expectProblem(($this->patch)(999_999, ['code' => 'X'], 'regional'), 403, 'FORBIDDEN');
        $this->actingAs(($this->users)('client'));
        expectProblem($this->postJson('/api/v1/admin/reason-codes', ['type' => 'NOPE']), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/admin/reason-codes?filter[type]=NOPE'), 403, 'FORBIDDEN');
    });

    it('has no way to delete a reason: they are deactivated', function () {
        $reason = ($this->find)('REJECTION', 'DOUBLON');
        $this->actingAs($this->admin);

        $this->deleteJson("/api/v1/admin/reason-codes/{$reason->id}")->assertStatus(405);
        $this->deleteJson('/api/v1/admin/reason-codes')->assertStatus(405);
        $this->putJson("/api/v1/admin/reason-codes/{$reason->id}", ['label' => 'X'])->assertStatus(405);
        expect(ReasonCode::query()->whereKey($reason->id)->exists())->toBeTrue();
    });
});

describe('list', function () {
    it('gives every reason, deactivated ones included, with the exact shape', function () {
        $reason = ($this->find)('ADJUSTMENT', 'BORNE_ATTEINTE');
        ($this->patch)($reason, ['is_active' => false])->assertOk();

        $response = ($this->as)()->getJson('/api/v1/admin/reason-codes?filter[type]=ADJUSTMENT')->assertOk();

        expect($response->json())->toBe(['data' => [
            [
                'id' => ($this->find)('ADJUSTMENT', 'QUOTA_INSUFFISANT')->id, 'type' => 'ADJUSTMENT', 'code' => 'QUOTA_INSUFFISANT', 'label' => 'Quota insuffisant',
                'requires_comment' => false, 'sort_order' => 10, 'is_active' => true,
                'created_at' => ($this->find)('ADJUSTMENT', 'QUOTA_INSUFFISANT')->created_at->toIso8601String(),
                'updated_at' => ($this->find)('ADJUSTMENT', 'QUOTA_INSUFFISANT')->updated_at->toIso8601String(),
            ],
            [
                'id' => $reason->id, 'type' => 'ADJUSTMENT', 'code' => 'BORNE_ATTEINTE', 'label' => 'Borne du client atteinte',
                'requires_comment' => false, 'sort_order' => 20, 'is_active' => false,
                'created_at' => $reason->fresh()->created_at->toIso8601String(), 'updated_at' => $reason->fresh()->updated_at->toIso8601String(),
            ],
            [
                'id' => ($this->find)('ADJUSTMENT', 'DEMANDE_CLIENT')->id, 'type' => 'ADJUSTMENT', 'code' => 'DEMANDE_CLIENT', 'label' => 'À la demande du client',
                'requires_comment' => false, 'sort_order' => 30, 'is_active' => true,
                'created_at' => ($this->find)('ADJUSTMENT', 'DEMANDE_CLIENT')->created_at->toIso8601String(),
                'updated_at' => ($this->find)('ADJUSTMENT', 'DEMANDE_CLIENT')->updated_at->toIso8601String(),
            ],
            [
                'id' => ($this->find)('ADJUSTMENT', 'AUTRE')->id, 'type' => 'ADJUSTMENT', 'code' => 'AUTRE', 'label' => 'Autre',
                'requires_comment' => true, 'sort_order' => 40, 'is_active' => true,
                'created_at' => ($this->find)('ADJUSTMENT', 'AUTRE')->created_at->toIso8601String(), 'updated_at' => ($this->find)('ADJUSTMENT', 'AUTRE')->updated_at->toIso8601String(),
            ],
        ]]);
    });

    it('filters by one or several types, none meaning all, ordered by type, then by order, then by label', function () {
        ($this->patch)(($this->find)('REJECTION', 'DOUBLON'), ['is_active' => false])->assertOk();

        $codes = fn (string $query) => collect(($this->as)()->getJson('/api/v1/admin/reason-codes'.$query)->assertOk()->json('data'))
            ->map(fn (array $reason) => "{$reason['type']}.{$reason['code']}")->all();

        expect($codes(''))->toHaveCount(17)
            ->and($codes('?filter[type]=SHIPPING_GAP'))->toBe(['SHIPPING_GAP.RUPTURE', 'SHIPPING_GAP.CASSE', 'SHIPPING_GAP.ERREUR_PREPARATION', 'SHIPPING_GAP.AUTRE'])
            ->and($codes('?filter[type]=rejection,CANCELLATION'))->toHaveCount(9)
            ->and($codes('?filter[type]=REJECTION'))->toContain('REJECTION.DOUBLON');
    });

    it('answers 422 to an unknown type or an unknown filter', function (string $query, string $field) {
        $response = ($this->as)()->getJson('/api/v1/admin/reason-codes'.$query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'type inconnu' => ['?filter[type]=PICKING', 'filter.type'],
        'élément vide' => ['?filter[type]=ADJUSTMENT,', 'filter.type'],
        'filtre inconnu' => ['?filter[code]=AUTRE', 'filter'],
    ]);
});

describe('creation', function () {
    it('creates a reason, active, with its defaults, and journals it', function () {
        $response = ($this->create)()->assertCreated();

        $reason = ($this->find)('REJECTION', 'RETARD_CAMION');
        expect($response->json())->toBe([
            'id' => $reason->id, 'type' => 'REJECTION', 'code' => 'RETARD_CAMION', 'label' => 'Retard du camion',
            'requires_comment' => false, 'sort_order' => 0, 'is_active' => true,
            'created_at' => $reason->created_at->toIso8601String(), 'updated_at' => $reason->updated_at->toIso8601String(),
        ]);
        $entry = AuditLog::query()->where('log_name', 'reasons')->where('event', 'created')->sole();
        expect($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('reason_code')
            ->and($entry->subject_id)->toBe($reason->id)
            ->and($entry->description)->toBe('Motif créé')
            ->and($entry->properties['attributes']['code'])->toBe('RETARD_CAMION');
    });

    it('creates a reason with a comment required and an order, usable at once in the lists of choice', function () {
        ($this->create)(['type' => 'ADJUSTMENT', 'code' => 'STOCK_PARTAGE', 'label' => '  Stock partagé ', 'requires_comment' => true, 'sort_order' => 15])
            ->assertCreated()
            ->assertJsonPath('label', 'Stock partagé')
            ->assertJsonPath('requires_comment', true)
            ->assertJsonPath('sort_order', 15);

        $codes = collect(($this->as)('regional')->getJson('/api/v1/reason-codes?type=ADJUSTMENT')->json('data'))->pluck('code')->all();
        expect($codes)->toBe(['QUOTA_INSUFFISANT', 'STOCK_PARTAGE', 'BORNE_ATTEINTE', 'DEMANDE_CLIENT', 'AUTRE']);
    });

    it('allows the same code for another type, but not twice for the same type', function () {
        ($this->create)(['type' => 'CANCELLATION', 'code' => 'RETARD_CAMION', 'label' => 'Retard du camion'])->assertCreated();
        ($this->create)(['type' => 'SHIPPING_GAP', 'code' => 'RETARD_CAMION', 'label' => 'Retard du camion'])->assertCreated();

        $again = ($this->create)(['type' => 'SHIPPING_GAP', 'code' => 'RETARD_CAMION', 'label' => 'Autre libellé']);
        expectProblem($again, 422, 'VALIDATION_FAILED');
        $again->assertJsonPath('errors', ['code' => ['Ce code existe déjà pour ce type de motif.']]);
        expect(ReasonCode::query()->where('code', 'RETARD_CAMION')->count())->toBe(2);
    });

    it('refuses an invalid reason with a 422 on the field, and creates nothing', function (array $body, array $errors) {
        $response = ($this->create)($body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        ($this->assertErrors)($response, $errors);
        expect(ReasonCode::query()->count())->toBe(17);
    })->with([
        'sans type' => [['type' => null], ['type' => null]],
        'type inconnu' => [['type' => 'PICKING'], ['type' => 'Type de motif inconnu : REJECTION, ADJUSTMENT, SHIPPING_GAP ou CANCELLATION.']],
        'sans code' => [['code' => null], ['code' => 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).']],
        'code en minuscules' => [['code' => 'retard'], ['code' => 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).']],
        'code commençant par un chiffre' => [['code' => '1ER'], ['code' => 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).']],
        'code d\'une lettre' => [['code' => 'A'], ['code' => 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).']],
        'code de 31 caractères' => [['code' => 'A'.str_repeat('B', 30)], ['code' => 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).']],
        'code pris' => [['code' => 'DOUBLON'], ['code' => 'Ce code existe déjà pour ce type de motif.']],
        'sans libellé' => [['label' => null], ['label' => 'Libellé obligatoire, 100 caractères au plus.']],
        'libellé de 101 caractères' => [['label' => str_repeat('x', 101)], ['label' => 'Libellé obligatoire, 100 caractères au plus.']],
        'libellé pris' => [['label' => 'Commande en double'], ['label' => 'Ce libellé existe déjà pour ce type de motif.']],
        'commentaire exigé non booléen' => [['requires_comment' => 'peut-être'], ['requires_comment' => null]],
        'ordre négatif' => [['sort_order' => -1], ['sort_order' => null]],
        'ordre trop grand' => [['sort_order' => 70000], ['sort_order' => null]],
        'ordre en texte' => [['sort_order' => 'premier'], ['sort_order' => null]],
        'écart d\'expédition exigeant un commentaire (I-95)' => [['type' => 'SHIPPING_GAP', 'requires_comment' => true], ['requires_comment' => 'Un motif d\'écart d\'expédition ne peut pas exiger de commentaire.']],
    ]);
});

describe('modification', function () {
    it('updates the label, the activation, the comment rule and the order, and journals the change with its author', function () {
        $reason = ($this->find)('ADJUSTMENT', 'DEMANDE_CLIENT');

        $response = ($this->patch)($reason, ['label' => 'Demandé par le client', 'requires_comment' => true, 'sort_order' => 5, 'is_active' => false])->assertOk();

        $reason->refresh();
        expect($response->json())->toBe([
            'id' => $reason->id, 'type' => 'ADJUSTMENT', 'code' => 'DEMANDE_CLIENT', 'label' => 'Demandé par le client',
            'requires_comment' => true, 'sort_order' => 5, 'is_active' => false,
            'created_at' => $reason->created_at->toIso8601String(), 'updated_at' => $reason->updated_at->toIso8601String(),
        ]);
        $entry = AuditLog::query()->where('log_name', 'reasons')->where('event', 'updated')->sole();
        expect($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->description)->toBe('Motif modifié')
            ->and($entry->properties['old'])->toEqual(['label' => 'À la demande du client', 'requires_comment' => false, 'sort_order' => 30, 'is_active' => true])
            ->and($entry->properties['attributes'])->toEqual(['label' => 'Demandé par le client', 'requires_comment' => true, 'sort_order' => 5, 'is_active' => false]);
    });

    it('deactivates a reason and takes it out of the lists of choice, then reactivates it (no deletion, I-82)', function () {
        $reason = ($this->find)('REJECTION', 'DOUBLON');
        $codes = fn () => collect(($this->as)('regional')->getJson('/api/v1/reason-codes?type=REJECTION')->json('data'))->pluck('code')->all();
        expect($codes())->toContain('DOUBLON');

        ($this->patch)($reason, ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        expect($codes())->not->toContain('DOUBLON');

        ($this->patch)($reason, ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        expect($codes())->toContain('DOUBLON')
            ->and(ReasonCode::query()->count())->toBe(17);
    });

    it('keeps a reason deactivated usable for the label of the orders already decided', function () {
        ($this->patch)(($this->find)('REJECTION', 'DOUBLON'), ['is_active' => false])->assertOk();

        expect(app(ReasonCodes::class)->label(ReasonType::Rejection, 'DOUBLON'))->toBe('Commande en double');
    });

    it('does not change the code nor the type: a 422 on the field, with no effect (I-82)', function (array $body, array $fields) {
        $reason = ($this->find)('REJECTION', 'DOUBLON');

        $response = ($this->patch)($reason, ['label' => 'Piraté', ...$body]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => $fields])
            ->assertJsonPath('errors.'.$fields[0], ['Le type et le code d\'un motif ne se modifient pas : désactivez-le et créez-en un autre.']);
        expect($reason->fresh()->only(['type', 'code', 'label']))->toEqual(['type' => ReasonType::Rejection, 'code' => 'DOUBLON', 'label' => 'Commande en double']);
        expect(AuditLog::query()->where('log_name', 'reasons')->where('event', 'updated')->count())->toBe(0);
    })->with([
        'code' => [['code' => 'AUTRE_CODE'], ['code']],
        'même code' => [['code' => 'DOUBLON'], ['code']],
        'type' => [['type' => 'ADJUSTMENT'], ['type']],
        'les deux' => [['type' => 'ADJUSTMENT', 'code' => 'NOUVEAU'], ['type', 'code']],
    ]);

    it('refuses an invalid change with a 422 on the field', function (array $body, array $errors) {
        $reason = ($this->find)('REJECTION', 'DOUBLON');

        $response = ($this->patch)($reason, $body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        ($this->assertErrors)($response, $errors);
        expect($reason->fresh()->label)->toBe('Commande en double');
    })->with([
        'libellé vide' => [['label' => ''], ['label' => 'Libellé obligatoire, 100 caractères au plus.']],
        'libellé nul' => [['label' => null], ['label' => 'Libellé obligatoire, 100 caractères au plus.']],
        'libellé de 101 caractères' => [['label' => str_repeat('x', 101)], ['label' => 'Libellé obligatoire, 100 caractères au plus.']],
        'libellé pris dans le type' => [['label' => 'Client bloqué'], ['label' => 'Ce libellé existe déjà pour ce type de motif.']],
        'activation non booléenne' => [['is_active' => 'oui'], ['is_active' => null]],
        'ordre négatif' => [['sort_order' => -3], ['sort_order' => null]],
        'ordre trop grand' => [['sort_order' => 65536], ['sort_order' => null]],
    ]);

    it('lets a label of another type, or its own label, be reused', function () {
        $reason = ($this->find)('REJECTION', 'DOUBLON');

        // « Rupture de stock » est celui d'un écart d'expédition : permis pour un rejet.
        ($this->patch)($reason, ['label' => 'Rupture de stock'])->assertOk()->assertJsonPath('label', 'Rupture de stock');
        ($this->patch)($reason, ['label' => 'Rupture de stock'])->assertOk();
    });

    it('refuses a comment required on a shipping gap reason (I-95)', function () {
        $response = ($this->patch)(($this->find)('SHIPPING_GAP', 'CASSE'), ['requires_comment' => true]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['requires_comment' => ['Un motif d\'écart d\'expédition ne peut pas exiger de commentaire.']]);
    });

    it('accepts an empty change without writing anything', function () {
        $reason = ($this->find)('REJECTION', 'DOUBLON');

        ($this->patch)($reason, [])->assertOk()->assertJsonPath('code', 'DOUBLON')->assertJsonPath('label', 'Commande en double');

        expect(AuditLog::query()->where('log_name', 'reasons')->count())->toBe(0);
    });

    it('answers 404 for an unknown reason, and for an identifier that is not a number', function () {
        expectProblem(($this->patch)(999_999, ['label' => 'X']), 404, 'NOT_FOUND');
        expectProblem(($this->patch)('99999999999999999999999', ['label' => 'X']), 404, 'NOT_FOUND');
        expectProblem(($this->patch)('doublon', ['label' => 'X']), 404, 'NOT_FOUND');
        expectProblem(($this->patch)('1.5', ['label' => 'X']), 404, 'NOT_FOUND');
    });
});
