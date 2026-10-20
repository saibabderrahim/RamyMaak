<?php

use App\Domain\Access\Role;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrderRejected;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\OrderValidated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ReasonCode;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// POST /regional/orders/{id}/reject (contrat du lot 6, §3) : rejet motivé,
// réponse = détail régional, jamais bloqué par l'heure limite de validation
// ni par le statut du client (I-70, I-71), seule une journée close le
// refuse ; quantités validées nulles, totaux inchangés, aucun compteur
// touché (I-74) ; le client peut renvoyer une commande (I-94).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00, validation jusqu'à 15:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class, OrderValidated::class, OrderRejected::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->titular = userWithRole(Role::Regional, ['name' => 'Karim Régional']);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional, ['name' => 'Samir Suppléant']);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'vat_rate' => '19.00']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'vat_rate' => '19.00']);
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '900.00', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->pomme->id, 'price_ht' => '850.50', 'valid_from' => '2026-01-01']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 100], [$this->pomme, 300]], [[$this->a, $this->orange, 50]]);
    $this->submit = fn (User $by, array $lines): Order => app(ClientOrders::class)->submit(
        $by,
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];
    $this->order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3]]);
    $this->post = fn (Order $order, array $body, ?User $by = null) => $this->actingAs($by ?? $this->titular)
        ->postJson("/api/v1/regional/orders/{$order->public_id}/reject", $body);
    $this->reject = fn (Order $order, string $reason = 'QUOTA_EPUISE', ?string $comment = null, ?User $by = null, ?int $version = null) => ($this->post)(
        $order,
        array_filter(['version' => $version ?? $order->fresh()->version, 'reason_code' => $reason, 'comment' => $comment], fn ($value) => $value !== null),
        $by,
    );
    $this->counter = fn (Product $product): int => RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $product->id)->value('validated_qty');
    $this->untouched = function (Order $order, int $version = 1): void {
        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Submitted)
            ->and($order->version)->toBe($version)
            ->and($order->decided_by)->toBeNull();
    };
    $this->settings = fn (array $values) => app(Settings::class)->update($values, null);
    $this->at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse("2026-10-08 {$moment}", 'Africa/Algiers'));
});

describe('rejection', function () {
    it('rejects an order with a reason and a comment: detail with the author, the label of the reason, totals and counters unchanged (I-74)', function () {
        $response = ($this->reject)($this->order, 'DOUBLON', '  Déjà reçue ce matin ')->assertOk();

        $response->assertJsonPath('id', $this->order->public_id)
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('status_label', 'Rejetée')
            ->assertJsonPath('version', 2)
            ->assertJsonPath('is_adjusted', false)
            ->assertJsonPath('decided_at', '2026-10-08T08:30:00+00:00')
            ->assertJsonPath('decided_by', ['id' => $this->titular->id, 'name' => 'Karim Régional'])
            ->assertJsonPath('decision_reason', ['code' => 'DOUBLON', 'label' => 'Commande en double'])
            ->assertJsonPath('decision_reason_code', 'DOUBLON')
            ->assertJsonPath('decision_comment', 'Déjà reçue ce matin')
            // Quantités validées nulles, totaux restés ceux de la demande (40 × 900 + 3 × 850,50).
            ->assertJsonPath('lines.*.validated_qty', [null, null])
            ->assertJsonPath('total_ht', 38551.5)
            ->assertJsonPath('history.1', [
                'from' => 'SUBMITTED', 'to' => 'REJECTED', 'version' => 2, 'at' => '2026-10-08T08:30:00+00:00',
                'actor' => 'REGIONAL', 'by_name' => 'Karim Régional', 'comment' => 'Déjà reçue ce matin',
            ])
            ->assertJsonPath('can_validate', false)
            ->assertJsonPath('can_reject', false);
        expect(($this->counter)($this->orange))->toBe(0)
            ->and(($this->counter)($this->pomme))->toBe(0)
            ->and($this->order->fresh()->decision_comment)->toBe('Déjà reçue ce matin');
        $audit = AuditLog::query()->where('log_name', 'orders')->where('event', 'rejected')->sole();
        expect($audit->causer_id)->toBe($this->titular->id)
            ->and($audit->properties->all())->toBe(['number' => 'RMK-261008-00001', 'version' => 2, 'reason' => 'DOUBLON', 'comment' => 'Déjà reçue ce matin']);
        Event::assertDispatchedTimes(OrderRejected::class, 1);
        Event::assertDispatched(OrderRejected::class, fn (OrderRejected $e) => $e->orderId === $this->order->id && $e->version === 2 && $e->actorId === $this->titular->id);
    });

    it('rejects without a comment when the reason does not demand one, and records the deputy as the author (RG-S03)', function () {
        ($this->reject)($this->order, 'QUOTA_EPUISE', by: $this->deputy)
            ->assertOk()
            ->assertJsonPath('decided_by.id', $this->deputy->id)
            ->assertJsonPath('decision_comment', null)
            ->assertJsonPath('history.1.by_name', 'Samir Suppléant')
            ->assertJsonPath('history.1.comment', null);
    });

    it('answers a resend of the same rejection with the current state and no effect (I-92)', function () {
        $first = ($this->reject)($this->order, 'DOUBLON', version: 1)->assertOk();

        $again = ($this->reject)($this->order, 'DOUBLON', version: 1)->assertOk();

        expect($again->json())->toBe($first->json())
            ->and($this->order->fresh()->version)->toBe(2)
            ->and($this->order->history()->count())->toBe(2)
            ->and(AuditLog::query()->where('event', 'rejected')->count())->toBe(1);
        Event::assertDispatchedTimes(OrderRejected::class, 1);
        // Un autre auteur n'est pas un renvoi.
        expectProblem(($this->reject)($this->order, 'DOUBLON', by: $this->deputy, version: 1), 409, 'INVALID_TRANSITION');
    });

    it('is never blocked by the validation cutoff, even blocking and passed, nor by a blocked client (I-70, I-71)', function () {
        ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
        $this->a->forceFill(['status' => 'BLOCKED'])->save();
        ($this->at)('16:30');

        // La validation est refusée, le rejet passe.
        $detail = $this->actingAs($this->titular)->getJson("/api/v1/regional/orders/{$this->order->public_id}")->assertOk();
        expect($detail->json('can_validate'))->toBeFalse()
            ->and($detail->json('can_reject'))->toBeTrue()
            ->and($detail->json('blocked_reason'))->toBe('CUTOFF_PASSED');
        ($this->reject)($this->order, 'CLIENT_BLOQUE')->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('decision_reason.label', 'Client bloqué');
    });

    it('frees the slot of the client, who can send a new order before the ordering cutoff (RG-C04, I-94)', function () {
        ($this->reject)($this->order, 'DOUBLON')->assertOk();

        $again = $this->actingAs($this->accountA)->postJson('/api/v1/client/orders', [
            'client_request_id' => (string) Str::uuid(), 'day' => '2026-10-08', 'comment' => null,
            'lines' => [['product_id' => $this->orange->id, 'quantity' => 40]],
        ])->assertCreated();

        expect($again->json('status'))->toBe('SUBMITTED')
            ->and($again->json('id'))->not->toBe($this->order->public_id);
    });

    it('rejects an order whose product was deactivated since it was sent, without touching the counters', function () {
        $this->pomme->forceFill(['is_active' => false])->save();

        ($this->reject)($this->order, 'QUOTA_EPUISE')->assertOk()->assertJsonPath('status', 'REJECTED');
        expect(($this->counter)($this->orange))->toBe(0);
    });
});

describe('shape (422)', function () {
    it('refuses a malformed body with a 422 on the field, before any other check, and with no effect', function (array $body, string $field) {
        $response = ($this->post)($this->order, $body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        ($this->untouched)($this->order);
    })->with([
        'sans version' => [['reason_code' => 'DOUBLON'], 'version'],
        'version en texte' => [['version' => '1', 'reason_code' => 'DOUBLON'], 'version'],
        'version à 0' => [['version' => 0, 'reason_code' => 'DOUBLON'], 'version'],
        'sans motif' => [['version' => 1], 'reason_code'],
        'motif vide' => [['version' => 1, 'reason_code' => ''], 'reason_code'],
        'motif inconnu' => [['version' => 1, 'reason_code' => 'INCONNU'], 'reason_code'],
        'motif d\'ajustement' => [['version' => 1, 'reason_code' => 'QUOTA_INSUFFISANT'], 'reason_code'],
        'motif d\'annulation' => [['version' => 1, 'reason_code' => 'ERREUR_SAISIE'], 'reason_code'],
        'motif en nombre' => [['version' => 1, 'reason_code' => 12], 'reason_code'],
        'motif en tableau' => [['version' => 1, 'reason_code' => ['DOUBLON']], 'reason_code'],
        'commentaire de 501 caractères' => [['version' => 1, 'reason_code' => 'DOUBLON', 'comment' => str_repeat('x', 501)], 'comment'],
        'commentaire en tableau' => [['version' => 1, 'reason_code' => 'DOUBLON', 'comment' => ['x']], 'comment'],
    ]);

    it('accepts a comment of 500 characters', function () {
        ($this->reject)($this->order, 'DOUBLON', str_repeat('é', 500))->assertOk();

        expect(mb_strlen($this->order->fresh()->decision_comment))->toBe(500);
    });

    it('refuses a deactivated rejection reason, and accepts it again once reactivated (EF-ADM-05)', function () {
        ReasonCode::query()->where('type', 'REJECTION')->where('code', 'DOUBLON')->update(['is_active' => false]);
        app(ReasonCodes::class)->flush();

        $response = ($this->reject)($this->order, 'DOUBLON', version: 1);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['reason_code' => ['Motif inconnu ou désactivé : choisissez un motif de la liste.']]);
        ($this->untouched)($this->order);

        ReasonCode::query()->where('type', 'REJECTION')->where('code', 'DOUBLON')->update(['is_active' => true]);
        app(ReasonCodes::class)->flush();
        ($this->reject)($this->order, 'DOUBLON', version: 1)->assertOk();
    });

    it('requires a comment when the reason demands one (AUTRE), after the checks of transition, version and day', function () {
        $response = ($this->reject)($this->order, 'AUTRE', version: 1);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['comment' => ['Commentaire obligatoire pour ce motif.']]);
        ($this->untouched)($this->order);

        ($this->reject)($this->order, 'AUTRE', 'Commande passée par erreur', version: 1)->assertOk()
            ->assertJsonPath('decision_reason', ['code' => 'AUTRE', 'label' => 'Autre'])
            ->assertJsonPath('decision_comment', 'Commande passée par erreur');
    });
});

describe('order of the checks (contrat §3)', function () {
    it('answers 422 on the shape of the body before the transition, even for an order already decided (422 before 409)', function () {
        ($this->reject)($this->order, 'DOUBLON')->assertOk();

        $response = ($this->post)($this->order, ['version' => 0, 'reason_code' => 'INCONNU']);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['version', 'reason_code']]);
    });

    it('answers 409 INVALID_TRANSITION for an order already decided, before the version', function () {
        ($this->reject)($this->order, 'DOUBLON')->assertOk();

        $response = ($this->reject)($this->order, 'QUOTA_EPUISE', version: 9);

        expectProblem($response, 409, 'INVALID_TRANSITION');
        $response->assertJsonPath('detail', 'Commande « Rejetée » : impossible de passer à « Rejetée ».');
        // Une commande validée ne se rejette plus non plus.
        $validated = ($this->submit)($this->accountA, [[$this->pomme, 1]]);
        $this->actingAs($this->titular)->postJson("/api/v1/regional/orders/{$validated->public_id}/validate", [
            'version' => 1, 'lines' => [['product_id' => $this->pomme->id, 'validated_qty' => 1]],
        ])->assertOk();
        expectProblem(($this->reject)($validated, 'DOUBLON', version: 2), 409, 'INVALID_TRANSITION');
    });

    it('answers 409 VERSION_CONFLICT with the current version after a modification of the client (R06)', function () {
        app(ClientOrders::class)->modify($this->accountA, $this->order->public_id, 1, [['product_id' => $this->orange->id, 'quantity' => 20]], null);

        $response = ($this->reject)($this->order, 'DOUBLON', version: 1);

        expectProblem($response, 409, 'VERSION_CONFLICT');
        $response->assertJsonPath('version', 2);
        ($this->untouched)($this->order, 2);
        ($this->reject)($this->order, 'DOUBLON', version: 2)->assertOk()->assertJsonPath('version', 3);
    });

    it('answers 409 DAY_NOT_OPEN for a closed day, before the sense of the comment', function () {
        BusinessDay::query()->update(['status' => 'CLOSED']);

        // Motif AUTRE sans commentaire : la journée d'abord.
        expectProblem(($this->reject)($this->order, 'AUTRE', version: 1), 409, 'DAY_NOT_OPEN');
        ($this->untouched)($this->order);
    });

    it('keeps the version conflict before the closed day', function () {
        BusinessDay::query()->update(['status' => 'CLOSED']);

        $response = ($this->reject)($this->order, 'DOUBLON', version: 4);

        expectProblem($response, 409, 'VERSION_CONFLICT');
        $response->assertJsonPath('version', 1);
    });
});
