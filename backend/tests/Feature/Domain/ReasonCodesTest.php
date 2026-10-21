<?php

use App\Domain\Reasons\ReasonCodeBook;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Models\AuditLog;
use App\Models\ReasonCode;
use App\Rules\ActiveReasonCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// Motifs (EF-ADM-05, §10.3, I-82) : motifs par défaut insérés par la
// migration, lecture (actifs, libellés des désactivés), écriture par
// l'Admin (code et type immuables, pas de suppression, journal `reasons`),
// règle de validation.

beforeEach(function () {
    $this->admin = admin();
    $this->reasons = app(ReasonCodes::class);
    $this->book = app(ReasonCodeBook::class);
    $this->codes = fn (ReasonType $type) => array_map(fn (ReasonCode $reason) => $reason->code, $this->reasons->active($type));
});

it('inserts the default reasons once, AUTRE requiring a comment except for a shipping gap (I-82, I-95, §11.3)', function () {
    expect(($this->codes)(ReasonType::Adjustment))->toBe(['QUOTA_INSUFFISANT', 'BORNE_ATTEINTE', 'DEMANDE_CLIENT', 'AUTRE'])
        ->and(($this->codes)(ReasonType::Rejection))->toBe(['QUOTA_EPUISE', 'CLIENT_BLOQUE', 'DOUBLON', 'DEMANDE_CLIENT', 'AUTRE'])
        ->and(($this->codes)(ReasonType::ShippingGap))->toBe(['RUPTURE', 'CASSE', 'ERREUR_PREPARATION', 'AUTRE'])
        ->and(($this->codes)(ReasonType::Cancellation))->toBe(['DEMANDE_CLIENT', 'RUPTURE', 'ERREUR_SAISIE', 'AUTRE'])
        ->and(ReasonCode::query()->where('requires_comment', true)->pluck('code')->unique()->values()->all())->toBe(['AUTRE'])
        ->and(ReasonCode::query()->where('requires_comment', true)->pluck('type')->map->value->sort()->values()->all())->toBe(['ADJUSTMENT', 'CANCELLATION', 'REJECTION'])
        ->and(ReasonCode::query()->count())->toBe(17)
        ->and($this->reasons->label(ReasonType::Adjustment, 'QUOTA_INSUFFISANT'))->toBe('Quota insuffisant')
        ->and($this->reasons->label(ReasonType::Rejection, 'DOUBLON'))->toBe('Commande en double')
        ->and($this->reasons->label(ReasonType::ShippingGap, 'RUPTURE'))->toBe('Rupture de stock');
});

it('resolves the label of a deactivated code, the code itself when unknown, null without a code', function () {
    $reason = ReasonCode::query()->where('type', 'REJECTION')->where('code', 'DOUBLON')->sole();
    $this->book->update($reason, ['is_active' => false], $this->admin);

    expect(($this->codes)(ReasonType::Rejection))->not->toContain('DOUBLON')
        ->and($this->reasons->isActive(ReasonType::Rejection, 'DOUBLON'))->toBeFalse()
        ->and($this->reasons->label(ReasonType::Rejection, 'DOUBLON'))->toBe('Commande en double')
        ->and($this->reasons->ref(ReasonType::Rejection, 'DOUBLON'))->toBe(['code' => 'DOUBLON', 'label' => 'Commande en double'])
        ->and($this->reasons->label(ReasonType::Rejection, 'ANCIEN_CODE'))->toBe('ANCIEN_CODE')
        ->and($this->reasons->ref(ReasonType::Rejection, null))->toBeNull()
        // Même code, autre type : un autre motif.
        ->and($this->reasons->isActive(ReasonType::Adjustment, 'DEMANDE_CLIENT'))->toBeTrue()
        ->and($this->reasons->isActive(ReasonType::ShippingGap, 'QUOTA_INSUFFISANT'))->toBeFalse();
});

it('reads every reason in a single query per request', function () {
    app()->forgetScopedInstances();
    $reasons = app(ReasonCodes::class);
    DB::enableQueryLog();

    $reasons->active(ReasonType::Adjustment);
    $reasons->label(ReasonType::Rejection, 'QUOTA_EPUISE');
    $reasons->requiresComment(ReasonType::Cancellation, 'AUTRE');

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('creates a reason with an immutable code, unique per type, and journals it (EF-ADM-05)', function () {
    $reason = $this->book->create(['type' => 'SHIPPING_GAP', 'code' => 'RETARD_CAMION', 'label' => '  Retard du camion ', 'sort_order' => 25], $this->admin);

    expect($reason->only(['code', 'label', 'requires_comment', 'is_active', 'sort_order']))->toBe([
        'code' => 'RETARD_CAMION', 'label' => 'Retard du camion', 'requires_comment' => false, 'is_active' => true, 'sort_order' => 25,
    ])
        ->and($reason->type)->toBe(ReasonType::ShippingGap)
        ->and(($this->codes)(ReasonType::ShippingGap))->toBe(['RUPTURE', 'CASSE', 'RETARD_CAMION', 'ERREUR_PREPARATION', 'AUTRE']);
    $entry = AuditLog::query()->where('log_name', 'reasons')->where('event', 'created')->sole();
    expect($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('reason_code')
        ->and($entry->description)->toBe('Motif créé')
        ->and($entry->properties['attributes']['code'])->toBe('RETARD_CAMION');

    // Le même code pour un autre type est permis.
    expect($this->book->create(['type' => ReasonType::Cancellation, 'code' => 'RETARD_CAMION', 'label' => 'Retard du camion'], $this->admin)->id)->not->toBe($reason->id);
});

it('refuses a malformed code, a code or a label already used for the type', function (array $data, string $field, string $message) {
    expect(fn () => $this->book->create($data, $this->admin))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe([$field => [$message]]));
})->with([
    'lowercase' => [['type' => 'REJECTION', 'code' => 'doublon', 'label' => 'X'], 'code', 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).'],
    'digit first' => [['type' => 'REJECTION', 'code' => '1ER', 'label' => 'X'], 'code', 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).'],
    'too long' => [['type' => 'REJECTION', 'code' => 'A'.str_repeat('B', 30), 'label' => 'X'], 'code', 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).'],
    'code taken' => [['type' => 'REJECTION', 'code' => 'DOUBLON', 'label' => 'Encore'], 'code', 'Ce code existe déjà pour ce type de motif.'],
    'label taken' => [['type' => 'REJECTION', 'code' => 'NOUVEAU', 'label' => 'Commande en double'], 'label', 'Ce libellé existe déjà pour ce type de motif.'],
    'empty label' => [['type' => 'REJECTION', 'code' => 'NOUVEAU', 'label' => '  '], 'label', 'Libellé obligatoire, 100 caractères au plus.'],
    'unknown type' => [['type' => 'PICKING', 'code' => 'NOUVEAU', 'label' => 'X'], 'type', 'Type de motif inconnu : REJECTION, ADJUSTMENT, SHIPPING_GAP ou CANCELLATION.'],
]);

it('updates the label, the activation, the comment rule and the order, never the code nor the type', function () {
    $reason = ReasonCode::query()->where('type', 'ADJUSTMENT')->where('code', 'DEMANDE_CLIENT')->sole();

    $updated = $this->book->update($reason, ['label' => 'Demandé par le client', 'requires_comment' => true, 'sort_order' => 5, 'ignored' => 'x'], $this->admin);

    expect($updated->fresh()->only(['code', 'label', 'requires_comment', 'sort_order']))->toBe([
        'code' => 'DEMANDE_CLIENT', 'label' => 'Demandé par le client', 'requires_comment' => true, 'sort_order' => 5,
    ])
        ->and($this->reasons->requiresComment(ReasonType::Adjustment, 'DEMANDE_CLIENT'))->toBeTrue()
        ->and(($this->codes)(ReasonType::Adjustment)[0])->toBe('DEMANDE_CLIENT');
    $entry = AuditLog::query()->where('log_name', 'reasons')->where('event', 'updated')->sole();
    expect($entry->properties['old'])->toEqual(['label' => 'À la demande du client', 'requires_comment' => false, 'sort_order' => 30])
        ->and($entry->causer_id)->toBe($this->admin->id);

    expect(fn () => $this->book->update($reason, ['code' => 'AUTRE_CODE', 'type' => 'REJECTION'], $this->admin))
        ->toThrow(fn (ValidationException $e) => expect(array_keys($e->errors()))->toBe(['type', 'code'])
            ->and($e->errors()['code'])->toBe(['Le type et le code d\'un motif ne se modifient pas : désactivez-le et créez-en un autre.']));
    expect(fn () => $this->book->update($reason, ['label' => 'Quota insuffisant'], $this->admin))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['label' => ['Ce libellé existe déjà pour ce type de motif.']]));
    // Le libellé inchangé n'entre pas en conflit avec lui-même.
    expect($this->book->update($reason, ['label' => 'Demandé par le client'], $this->admin)->label)->toBe('Demandé par le client');
});

it('never deletes a reason', function () {
    $reason = ReasonCode::query()->firstOrFail();

    expect(fn () => $reason->delete())->toThrow(LogicException::class, 'Un motif ne se supprime pas : il se désactive.')
        ->and(ReasonCode::query()->whereKey($reason->id)->exists())->toBeTrue();
});

it('accepts only an active code of the given type in a request (ActiveReasonCode)', function () {
    ReasonCode::factory()->type(ReasonType::Adjustment)->inactive()->create(['code' => 'ANCIEN']);
    $check = fn (mixed $value) => Validator::make(['reason_code' => $value], ['reason_code' => [new ActiveReasonCode(ReasonType::Adjustment)]]);

    expect($check('QUOTA_INSUFFISANT')->passes())->toBeTrue()
        ->and($check('QUOTA_EPUISE')->passes())->toBeFalse()
        ->and($check('ANCIEN')->passes())->toBeFalse()
        ->and($check(['QUOTA_INSUFFISANT'])->passes())->toBeFalse()
        ->and($check('INCONNU')->errors()->first('reason_code'))->toBe('Motif inconnu ou désactivé : choisissez un motif de la liste.');
});

it('checks a decision reason and its comment (rejection, cancellation)', function () {
    expect($this->reasons->assertChoice(ReasonType::Rejection, ' QUOTA_EPUISE ', '  ', 500))->toBe(['code' => 'QUOTA_EPUISE', 'comment' => null])
        ->and($this->reasons->assertChoice(ReasonType::Rejection, 'AUTRE', ' Doublon du matin ', 500))->toBe(['code' => 'AUTRE', 'comment' => 'Doublon du matin']);

    $errors = function (ReasonType $type, ?string $code, ?string $comment): array {
        try {
            $this->reasons->assertChoice($type, $code, $comment, 500);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        throw new RuntimeException('ValidationException attendue');
    };
    expect($errors(ReasonType::Rejection, 'AUTRE', null))->toBe(['comment' => ['Commentaire obligatoire pour ce motif.']])
        ->and($errors(ReasonType::Cancellation, 'QUOTA_EPUISE', null))->toBe(['reason_code' => ['Motif inconnu ou désactivé : choisissez un motif de la liste.']])
        ->and($errors(ReasonType::Rejection, 'DOUBLON', str_repeat('a', 501)))->toBe(['comment' => ['Le commentaire ne peut pas dépasser 500 caractères.']]);
});

it('never lets a shipping gap reason require a comment, which no line could keep (I-95)', function () {
    expect(fn () => $this->book->create(['type' => 'SHIPPING_GAP', 'code' => 'PERTE', 'label' => 'Perte', 'requires_comment' => true], $this->admin))
        ->toThrow(ValidationException::class);
    $gap = ReasonCode::query()->where('type', 'SHIPPING_GAP')->where('code', 'CASSE')->sole();
    expect(fn () => $this->book->update($gap, ['requires_comment' => true], $this->admin))
        ->toThrow(ValidationException::class);
    expect($this->book->update($gap, ['requires_comment' => false], $this->admin)->requires_comment)->toBeFalse();
});
