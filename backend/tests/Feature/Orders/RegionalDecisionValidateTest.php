<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
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

// POST /regional/orders/{id}/validate (contrat du lot 6, §3) : validation
// avec ou sans ajustement, réponse = détail régional, renvoi idempotent,
// forme (422), ordre des contrôles (409, 403, 422 de sens), refus de quota ou
// de borne avec toutes les lignes en cause, heure limite de validation.
// Les droits et le périmètre sont dans RegionalDecisionAccessTest.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00, validation jusqu'à 15:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class, OrderValidated::class, OrderRejected::class,
    ]);
    app(Settings::class)->update(['orders' => ['max_per_day' => 3]], null);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->titular = userWithRole(Role::Regional, ['name' => 'Karim Régional']);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional, ['name' => 'Samir Suppléant']);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'name' => 'Alimentation Nour', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'vat_rate' => '19.00']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'vat_rate' => '19.00']);
    $this->lben = Product::factory()->create(['sku' => 'P0103', 'name' => 'Lben 1 L', 'vat_rate' => '9.00']);
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '900.00', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->pomme->id, 'price_ht' => '850.50', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->lben->id, 'price_ht' => '120.00', 'valid_from' => '2026-01-01']);

    // Orange : quota 100, borne 50 pour A ; pomme 300 et lben 800 sans borne.
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 100], [$this->pomme, 300], [$this->lben, 800]], [
        [$this->a, $this->orange, 50],
    ]);
    $this->submit = fn (User $by, array $lines): Order => app(ClientOrders::class)->submit(
        $by,
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];
    // [[Product, validé, motif?, commentaire?], …] dans l'ordre donné.
    $this->lines = fn (array $lines): array => array_map(fn (array $line) => array_filter([
        'product_id' => $line[0]->id,
        'validated_qty' => $line[1],
        'reason_code' => $line[2] ?? null,
        'comment' => $line[3] ?? null,
    ], fn ($value) => $value !== null), $lines);
    $this->post = fn (Order $order, array $body, ?User $by = null) => $this->actingAs($by ?? $this->titular)
        ->postJson("/api/v1/regional/orders/{$order->public_id}/validate", $body);
    $this->validate = fn (Order $order, array $lines, ?User $by = null, ?int $version = null) => ($this->post)(
        $order,
        ['version' => $version ?? $order->fresh()->version, 'lines' => ($this->lines)($lines)],
        $by,
    );
    $this->counter = fn (Product $product): int => RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $product->id)->value('validated_qty');
    $this->untouched = function (Order $order, int $version = 1): void {
        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Submitted)
            ->and($order->version)->toBe($version)
            ->and($order->decided_by)->toBeNull()
            ->and($order->lines()->whereNotNull('validated_qty')->count())->toBe(0);
    };
    $this->settings = fn (array $values) => app(Settings::class)->update($values, null);
    $this->at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse("2026-10-08 {$moment}", 'Africa/Algiers'));
    // Commande standard : A demande 40 orange, 3 pomme, 7 lben.
    $this->order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]]);
});

describe('validation', function () {
    it('validates an order in full and answers the regional detail: validated quantities, context, author, no more action', function () {
        $response = ($this->validate)($this->order, [[$this->lben, 7], [$this->orange, 40], [$this->pomme, 3]])->assertOk();

        $response->assertJsonPath('id', $this->order->public_id)
            ->assertJsonPath('number', 'RMK-261008-00001')
            ->assertJsonPath('status', 'VALIDATED')
            ->assertJsonPath('status_label', 'Validée')
            ->assertJsonPath('version', 2)
            ->assertJsonPath('is_adjusted', false)
            ->assertJsonPath('decided_at', '2026-10-08T08:30:00+00:00')
            ->assertJsonPath('decided_by', ['id' => $this->titular->id, 'name' => 'Karim Régional'])
            ->assertJsonPath('decision_reason', null)
            // Totaux sur les quantités validées, toutes égales aux quantités demandées ici.
            ->assertJsonPath('total_ht', 39391.5)
            ->assertJsonPath('total_vat', 7400.39)
            ->assertJsonPath('total_ttc', 46791.89)
            ->assertJsonPath('lines.*.product_id', [$this->orange->id, $this->pomme->id, $this->lben->id])
            ->assertJsonPath('lines.*.validated_qty', [40, 3, 7])
            ->assertJsonPath('lines.*.adjustment_reason', [null, null, null])
            ->assertJsonPath('lines.0.amount_ht', 36000)
            // Contexte relu après la décision : V(c,p) et V(r,p) comptent la commande, validable = sa propre quantité.
            ->assertJsonPath('lines.0.context', ['limit' => 50, 'client_validated' => 40, 'region_quota' => 100, 'region_validated' => 40, 'region_remaining' => 60, 'max_validatable' => 40])
            ->assertJsonPath('lines.1.context', ['limit' => null, 'client_validated' => 3, 'region_quota' => 300, 'region_validated' => 3, 'region_remaining' => 297, 'max_validatable' => 3])
            ->assertJsonPath('history.1', [
                'from' => 'SUBMITTED', 'to' => 'VALIDATED', 'version' => 2, 'at' => '2026-10-08T08:30:00+00:00',
                'actor' => 'REGIONAL', 'by_name' => 'Karim Régional', 'comment' => null,
            ])
            ->assertJsonPath('can_validate', false)
            ->assertJsonPath('can_reject', false)
            ->assertJsonPath('blocked_reason', null)
            ->assertJsonPath('validation.cutoff', '15:00');
        expect(($this->counter)($this->orange))->toBe(40)
            ->and(($this->counter)($this->pomme))->toBe(3)
            ->and(($this->counter)($this->lben))->toBe(7);
        $audit = AuditLog::query()->where('log_name', 'orders')->where('event', 'validated')->sole();
        expect($audit->causer_id)->toBe($this->titular->id)
            ->and($audit->properties['adjusted_lines'])->toBe(0);
        Event::assertDispatchedTimes(OrderValidated::class, 1);
        Event::assertDispatched(OrderValidated::class, fn (OrderValidated $e) => $e->orderId === $this->order->id && $e->version === 2
            && $e->actorId === $this->titular->id && $e->adjustedLines === 0);
    });

    it('validates with adjustments by the deputy: reasons with labels, comments, totals on the validated quantities (RG-V04, RG-P04, I-73)', function () {
        $response = ($this->validate)($this->order, [
            [$this->orange, 30, 'QUOTA_INSUFFISANT', '  Reste région limité '],
            [$this->pomme, 0, 'AUTRE', 'Plus de pomme'],
            [$this->lben, 7],
        ], $this->deputy)->assertOk();

        // HT : 27 000 + 0 + 840 ; TVA : 5 130 + 75,60 (prix et TVA figés).
        $response->assertJsonPath('status', 'VALIDATED')
            ->assertJsonPath('is_adjusted', true)
            ->assertJsonPath('decided_by', ['id' => $this->deputy->id, 'name' => 'Samir Suppléant'])
            ->assertJsonPath('total_ht', 27840)
            ->assertJsonPath('total_vat', 5205.6)
            ->assertJsonPath('total_ttc', 33045.6)
            ->assertJsonPath('lines.*.requested_qty', [40, 3, 7])
            ->assertJsonPath('lines.*.validated_qty', [30, 0, 7])
            ->assertJsonPath('lines.*.amount_ht', [27000, 0, 840])
            ->assertJsonPath('lines.0.adjustment_reason_code', 'QUOTA_INSUFFISANT')
            ->assertJsonPath('lines.0.adjustment_reason', ['code' => 'QUOTA_INSUFFISANT', 'label' => 'Quota insuffisant'])
            ->assertJsonPath('lines.0.adjustment_comment', 'Reste région limité')
            ->assertJsonPath('lines.1.adjustment_reason', ['code' => 'AUTRE', 'label' => 'Autre'])
            ->assertJsonPath('lines.1.adjustment_comment', 'Plus de pomme')
            ->assertJsonPath('lines.2.adjustment_reason', null)
            ->assertJsonPath('lines.2.adjustment_comment', null)
            ->assertJsonPath('history.1.by_name', 'Samir Suppléant');
        // Une ligne validée à 0 ne touche pas le compteur (§5.8).
        expect(($this->counter)($this->orange))->toBe(30)
            ->and(($this->counter)($this->pomme))->toBe(0)
            ->and(($this->counter)($this->lben))->toBe(7)
            ->and(AuditLog::query()->where('event', 'validated')->sole()->properties['adjusted_lines'])->toBe(2);
        Event::assertDispatched(OrderValidated::class, fn (OrderValidated $e) => $e->adjustedLines === 2 && $e->actorId === $this->deputy->id);
    });

    it('answers a resend of the same decision with the current state and no effect (I-92)', function () {
        $lines = [[$this->orange, 30, 'QUOTA_INSUFFISANT'], [$this->pomme, 3], [$this->lben, 7]];
        $first = ($this->validate)($this->order, $lines, version: 1)->assertOk();

        // Renvoi (réponse perdue) : même version, même auteur.
        $again = ($this->validate)($this->order, $lines, version: 1)->assertOk();

        expect($again->json())->toBe($first->json())
            ->and($this->order->fresh()->version)->toBe(2)
            ->and($this->order->history()->count())->toBe(2)
            ->and(AuditLog::query()->where('event', 'validated')->count())->toBe(1)
            ->and(($this->counter)($this->orange))->toBe(30);
        Event::assertDispatchedTimes(OrderValidated::class, 1);

        // Un autre auteur, ou une autre version, n'est pas un renvoi.
        expectProblem(($this->validate)($this->order, $lines, $this->deputy, version: 1), 409, 'INVALID_TRANSITION');
        expectProblem(($this->validate)($this->order, $lines, version: 2), 409, 'INVALID_TRANSITION');
        expect(($this->counter)($this->orange))->toBe(30);
    });

    it('answers a conflict when the client modified the order, then validates the new version (RG-V06, R06)', function () {
        app(ClientOrders::class)->modify($this->accountA, $this->order->public_id, 1, [
            ['product_id' => $this->orange->id, 'quantity' => 20], ['product_id' => $this->pomme->id, 'quantity' => 3], ['product_id' => $this->lben->id, 'quantity' => 7],
        ], 'Modifiée');

        $conflict = ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1);

        expectProblem($conflict, 409, 'VERSION_CONFLICT');
        $conflict->assertJsonPath('version', 2);
        expect(($this->counter)($this->orange))->toBe(0);
        // La version relue est la bonne : la décision passe.
        $response = ($this->validate)($this->order, [[$this->orange, 20], [$this->pomme, 3], [$this->lben, 7]], version: 2)->assertOk();
        $response->assertJsonPath('version', 3)->assertJsonPath('lines.0.validated_qty', 20);
    });

    it('never caps a quantity by itself: the regional validates exactly what he sends (D-06)', function () {
        $other = ($this->submit)($this->accountB, [[$this->orange, 70]]);
        ($this->validate)($other, [[$this->orange, 70]])->assertOk();

        // Reste région : 30 sur l'orange ; 40 demandés → refus, jamais une validation à 30.
        expectProblem(($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]]), 409, 'REGION_QUOTA_EXCEEDED');
        expect(($this->counter)($this->orange))->toBe(70);
        ($this->untouched)($this->order);
    });

    it('validates a line at the maximum announced by the detail (« Ajuster au maximum possible »)', function () {
        $other = ($this->submit)($this->accountB, [[$this->orange, 70]]);
        ($this->validate)($other, [[$this->orange, 70]])->assertOk();

        $detail = $this->actingAs($this->titular)->getJson("/api/v1/regional/orders/{$this->order->public_id}")->assertOk();
        $max = $detail->json('lines.0.context.max_validatable');
        expect($max)->toBe(30);

        ($this->validate)($this->order, [[$this->orange, $max, 'QUOTA_INSUFFISANT'], [$this->pomme, 3], [$this->lben, 7]])
            ->assertOk()
            ->assertJsonPath('lines.0.validated_qty', 30)
            ->assertJsonPath('lines.0.context.region_remaining', 0)
            ->assertJsonPath('lines.0.context.max_validatable', 30);
        expect(($this->counter)($this->orange))->toBe(100);
    });
});

describe('shape (422)', function () {
    it('refuses a malformed body with a 422 on the field, before any other check, and with no effect', function (array $override, string $field) {
        $body = [...['version' => 1, 'lines' => [['product_id' => $this->orange->id, 'validated_qty' => 40], ['product_id' => $this->pomme->id, 'validated_qty' => 3]]], ...$override];
        $response = ($this->post)($this->order, $body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        ($this->untouched)($this->order);
    })->with([
        'sans version' => [['version' => null], 'version'],
        'version en texte' => [['version' => '1'], 'version'],
        'version à 0' => [['version' => 0], 'version'],
        'version décimale' => [['version' => 1.5], 'version'],
        'sans lignes' => [['lines' => null], 'lines'],
        'lignes vides' => [['lines' => []], 'lines'],
        'lignes en objet' => [['lines' => ['a' => ['product_id' => 1, 'validated_qty' => 1]]], 'lines'],
        'ligne sans produit' => [['lines' => [['validated_qty' => 3]]], 'lines.0.product_id'],
        'produit en texte' => [['lines' => [['product_id' => 'orange', 'validated_qty' => 3]]], 'lines.0.product_id'],
        'ligne sans quantité' => [['lines' => [['product_id' => 42]]], 'lines.0.validated_qty'],
        'quantité en texte' => [['lines' => [['product_id' => 42, 'validated_qty' => '12']]], 'lines.0.validated_qty'],
        'quantité négative' => [['lines' => [['product_id' => 42, 'validated_qty' => -1]]], 'lines.0.validated_qty'],
        'quantité décimale' => [['lines' => [['product_id' => 42, 'validated_qty' => 2.5]]], 'lines.0.validated_qty'],
        'quantité booléenne' => [['lines' => [['product_id' => 42, 'validated_qty' => true]]], 'lines.0.validated_qty'],
        'quantité au-delà du million' => [['lines' => [['product_id' => 42, 'validated_qty' => 1_000_001]]], 'lines.0.validated_qty'],
        'motif inconnu' => [['lines' => [['product_id' => 42, 'validated_qty' => 1, 'reason_code' => 'INCONNU']]], 'lines.0.reason_code'],
        'motif de rejet' => [['lines' => [['product_id' => 42, 'validated_qty' => 1, 'reason_code' => 'QUOTA_EPUISE']]], 'lines.0.reason_code'],
        'motif en nombre' => [['lines' => [['product_id' => 42, 'validated_qty' => 1, 'reason_code' => 12]]], 'lines.0.reason_code'],
        'commentaire de 201 caractères' => [['lines' => [['product_id' => 42, 'validated_qty' => 1, 'reason_code' => 'AUTRE', 'comment' => str_repeat('x', 201)]]], 'lines.0.comment'],
        'commentaire en tableau' => [['lines' => [['product_id' => 42, 'validated_qty' => 1, 'comment' => ['x']]]], 'lines.0.comment'],
        'ligne en texte' => [['lines' => ['orange']], 'lines.0'],
    ]);

    it('refuses more than 200 lines, and the same product twice (on the second occurrence)', function () {
        $many = array_map(fn (int $id) => ['product_id' => $id, 'validated_qty' => 1], range(1, 201));
        $response = ($this->post)($this->order, ['version' => 1, 'lines' => $many]);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['lines']]);

        $twice = ($this->post)($this->order, ['version' => 1, 'lines' => [
            ['product_id' => $this->orange->id, 'validated_qty' => 40], ['product_id' => $this->pomme->id, 'validated_qty' => 3], ['product_id' => $this->orange->id, 'validated_qty' => 40],
        ]]);
        expectProblem($twice, 422, 'VALIDATION_FAILED');
        $twice->assertJsonPath('errors', ['lines.2.product_id' => ['Ce produit figure deux fois.']]);
        ($this->untouched)($this->order);
    });

    it('refuses a deactivated adjustment reason, and accepts it again once reactivated (EF-ADM-05)', function () {
        ReasonCode::query()->where('type', 'ADJUSTMENT')->where('code', 'QUOTA_INSUFFISANT')->update(['is_active' => false]);
        app(ReasonCodes::class)->flush();
        $lines = [[$this->orange, 30, 'QUOTA_INSUFFISANT'], [$this->pomme, 3], [$this->lben, 7]];

        $response = ($this->validate)($this->order, $lines, version: 1);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['lines.0.reason_code' => ['Motif inconnu ou désactivé : choisissez un motif de la liste.']]);
        ($this->untouched)($this->order);

        ReasonCode::query()->where('type', 'ADJUSTMENT')->where('code', 'QUOTA_INSUFFISANT')->update(['is_active' => true]);
        app(ReasonCodes::class)->flush();
        ($this->validate)($this->order, $lines, version: 1)->assertOk();
    });
});

describe('sense of the lines (422 after the locks)', function () {
    it('refuses the lines that are not exactly the products of the order', function (string $case) {
        $orange = ['product_id' => $this->orange->id, 'validated_qty' => 40];
        $pomme = ['product_id' => $this->pomme->id, 'validated_qty' => 3];
        $lben = ['product_id' => $this->lben->id, 'validated_qty' => 7];
        $lines = match ($case) {
            'missing' => [$orange, $pomme],
            'extra' => [$orange, $pomme, $lben, ['product_id' => 999_999, 'validated_qty' => 1]],
            'foreign' => [$orange, $pomme, ['product_id' => 999_999, 'validated_qty' => 7]],
        };

        $response = ($this->post)($this->order, ['version' => 1, 'lines' => $lines]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['lines' => ['Les lignes doivent reprendre exactement les produits de la commande, une fois chacun.']]);
        ($this->untouched)($this->order);
    })->with(['missing', 'extra', 'foreign']);

    it('reports every line in error at once, keyed by the index of the line', function () {
        $response = ($this->post)($this->order, ['version' => 1, 'lines' => [
            ['product_id' => $this->orange->id, 'validated_qty' => 41],
            ['product_id' => $this->pomme->id, 'validated_qty' => 2],
            ['product_id' => $this->lben->id, 'validated_qty' => 7, 'reason_code' => 'AUTRE', 'comment' => 'Pourquoi'],
        ]]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', [
            'lines.0.validated_qty' => ['La quantité validée doit être un nombre entier de 0 à 40 (quantité demandée).'],
            'lines.1.reason_code' => ['Motif obligatoire pour une ligne réduite.'],
            'lines.2.reason_code' => ['Pas de motif sur une ligne validée en entier.'],
            'lines.2.comment' => ['Pas de commentaire sur une ligne validée en entier.'],
        ]);
        ($this->untouched)($this->order);
    });

    it('requires a comment when the reason demands one (AUTRE), on a reduced line only', function () {
        $response = ($this->validate)($this->order, [[$this->orange, 30, 'AUTRE'], [$this->pomme, 3], [$this->lben, 7]], version: 1);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['lines.0.comment' => ['Commentaire obligatoire pour ce motif.']]);
        ($this->untouched)($this->order);

        ($this->validate)($this->order, [[$this->orange, 30, 'AUTRE', 'Quota partagé'], [$this->pomme, 3], [$this->lben, 7]], version: 1)
            ->assertOk()
            ->assertJsonPath('lines.0.adjustment_comment', 'Quota partagé');
    });

    it('asks to reject the order when every line is at 0 (RG-V05)', function () {
        $response = ($this->validate)($this->order, [
            [$this->orange, 0, 'QUOTA_INSUFFISANT'], [$this->pomme, 0, 'QUOTA_INSUFFISANT'], [$this->lben, 0, 'QUOTA_INSUFFISANT'],
        ], version: 1);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors', ['lines' => ['Toutes les lignes sont à 0 : rejetez la commande.']]);
        ($this->untouched)($this->order);
        expect(($this->counter)($this->orange))->toBe(0);
    });

    it('lets a single line stay above 0 while the others are at 0', function () {
        ($this->validate)($this->order, [[$this->orange, 0, 'QUOTA_INSUFFISANT'], [$this->pomme, 0, 'QUOTA_INSUFFISANT'], [$this->lben, 1, 'QUOTA_INSUFFISANT']], version: 1)
            ->assertOk()
            ->assertJsonPath('lines.*.validated_qty', [0, 0, 1])
            ->assertJsonPath('total_ht', 120);
    });
});

describe('order of the checks (contrat §3)', function () {
    beforeEach(function () {
        $this->full = fn () => [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]];
        $this->closeDay = fn () => BusinessDay::query()->update(['status' => 'CLOSED']);
    });

    it('answers 422 on the shape of the body before the transition, even for an order already decided (422 before 409)', function () {
        $rejected = ($this->submit)($this->accountB, [[$this->pomme, 2]]);
        $this->actingAs($this->titular)->postJson("/api/v1/regional/orders/{$rejected->public_id}/reject", ['version' => 1, 'reason_code' => 'DOUBLON'])->assertOk();

        $response = ($this->post)($rejected, ['version' => 'x', 'lines' => []]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['version', 'lines']]);
    });

    it('answers 409 INVALID_TRANSITION for an order already decided, before the version and the day', function () {
        $rejected = ($this->submit)($this->accountB, [[$this->pomme, 2]]);
        $this->actingAs($this->titular)->postJson("/api/v1/regional/orders/{$rejected->public_id}/reject", ['version' => 1, 'reason_code' => 'DOUBLON'])->assertOk();
        ($this->closeDay)();

        // Version périmée et journée close : le statut d'abord.
        $response = ($this->validate)($rejected, [[$this->pomme, 2]], version: 7);

        expectProblem($response, 409, 'INVALID_TRANSITION');
        $response->assertJsonPath('detail', 'Commande « Rejetée » : impossible de passer à « Validée ».');
    });

    it('answers 409 VERSION_CONFLICT with the current version, before the day', function () {
        ($this->closeDay)();

        $response = ($this->validate)($this->order, ($this->full)(), version: 5);

        expectProblem($response, 409, 'VERSION_CONFLICT');
        $response->assertJsonPath('version', 1);
    });

    it('answers 409 DAY_NOT_OPEN for a closed day or a closed region day, before the cutoff', function (string $closed) {
        ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
        ($this->at)('15:10');
        match ($closed) {
            'day' => ($this->closeDay)(),
            'region day' => $this->regionDay->forceFill(['status' => 'CLOSED'])->save(),
        };

        $response = ($this->validate)($this->order, ($this->full)(), version: 1);

        expectProblem($response, 409, 'DAY_NOT_OPEN');
        $response->assertJsonMissingPath('cutoff');
        ($this->untouched)($this->order);
    })->with(['day', 'region day']);

    it('answers 409 CUTOFF_PASSED with the cutoff when the validation cutoff is blocking and passed, before the client status (RG-V08)', function () {
        ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
        $this->a->forceFill(['status' => 'BLOCKED'])->save();
        ($this->at)('15:00');

        $response = ($this->validate)($this->order, ($this->full)(), version: 1);

        expectProblem($response, 409, 'CUTOFF_PASSED');
        $response->assertJsonPath('cutoff', '15:00')
            ->assertJsonPath('detail', 'L\'heure limite de validation (15:00) est dépassée : la commande ne peut plus être validée, seulement rejetée.');
        ($this->untouched)($this->order);
    });

    it('answers 403 CLIENT_BLOCKED for a blocked or inactive client, before the sense of the lines', function (string $status) {
        $this->a->forceFill(['status' => $status])->save();

        // Lignes absurdes : le client d'abord.
        $response = ($this->post)($this->order, ['version' => 1, 'lines' => [['product_id' => $this->orange->id, 'validated_qty' => 4000]]]);

        expectProblem($response, 403, 'CLIENT_BLOCKED');
        ($this->untouched)($this->order);
    })->with(['BLOCKED', 'INACTIVE']);

    it('answers 422 on the sense of the lines before the ceilings', function () {
        $other = ($this->submit)($this->accountB, [[$this->orange, 70]]);
        ($this->validate)($other, [[$this->orange, 70]])->assertOk();

        // Reste région 30 : l'excès de 40 demandés (41 > 40) est d'abord une erreur de saisie.
        $response = ($this->validate)($this->order, [[$this->orange, 41], [$this->pomme, 3], [$this->lben, 7]], version: 1);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        ($this->untouched)($this->order);
    });
});

describe('validation cutoff (RG-V08, I-71)', function () {
    it('does not block the validation after the cutoff by default', function () {
        ($this->at)('18:30');

        ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1)
            ->assertOk()
            ->assertJsonPath('validation', ['cutoff' => '15:00', 'cutoff_at' => '2026-10-08T14:00:00+00:00', 'extended' => false, 'blocking' => false, 'passed' => true]);
    });

    it('blocks the validation only from the cutoff when it is blocking, and the ADV extension of the region reopens it', function () {
        $other = ($this->submit)($this->accountB, [[$this->pomme, 2]]);
        ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
        // Une minute avant l'heure limite : permis.
        ($this->at)('14:59');
        ($this->validate)($other, [[$this->pomme, 2]])->assertOk();

        ($this->at)('15:10');
        expectProblem(($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1), 409, 'CUTOFF_PASSED');

        $adv = userWithRole(Role::Adv);
        app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Validation, '15:30', $adv);
        ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1)
            ->assertOk()
            ->assertJsonPath('validation', ['cutoff' => '15:30', 'cutoff_at' => '2026-10-08T14:30:00+00:00', 'extended' => true, 'blocking' => true, 'passed' => false]);
    });

    it('reports the extended cutoff when it is passed again', function () {
        ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
        ($this->at)('15:10');
        app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Validation, '15:30', userWithRole(Role::Adv));
        ($this->at)('15:45');

        $response = ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1);

        expectProblem($response, 409, 'CUTOFF_PASSED');
        $response->assertJsonPath('cutoff', '15:30');
    });
});

describe('ceilings of the region and of the client (RG-V02, RG-V03, I-69)', function () {
    it('refuses with REGION_QUOTA_EXCEEDED and the line, its numbers, its cause and the maximum allowed', function () {
        $other = ($this->submit)($this->accountB, [[$this->orange, 70]]);
        ($this->validate)($other, [[$this->orange, 70]])->assertOk();

        $response = ($this->validate)($this->order, [[$this->lben, 7], [$this->orange, 40], [$this->pomme, 3]], version: 1);

        expectProblem($response, 409, 'REGION_QUOTA_EXCEEDED');
        $response->assertJsonPath('title', 'Quota région dépassé')
            ->assertJsonPath('detail', 'La validation dépasserait le quota de la région pour 1 produit.')
            // « line » : indice dans les lignes envoyées (lben, orange, pomme) → orange = 1.
            ->assertJsonPath('violations', [[
                'line' => 1, 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 40, 'quota' => 100, 'validated' => 70,
                'limit' => 50, 'client_validated' => 0, 'max_allowed' => 30, 'cause' => 'REGION_QUOTA',
            ]]);
        // Toute la validation est refusée : aucun compteur, aucune ligne.
        expect(($this->counter)($this->lben))->toBe(0)
            ->and(($this->counter)($this->pomme))->toBe(0)
            ->and(($this->counter)($this->orange))->toBe(70);
        ($this->untouched)($this->order);
        Event::assertDispatchedTimes(OrderValidated::class, 1);
    });

    it('refuses with CLIENT_LIMIT_EXCEEDED when only the borne of the client is exceeded, with a null limit for a product without borne', function () {
        ($this->settings)(['orders' => ['max_per_day' => 2]]);
        $second = ($this->submit)($this->accountA, [[$this->orange, 10]]);
        ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1)->assertOk();
        // Le régional baisse ensuite la borne de A sur l'orange à 45 (≥ V(c,p) = 40) : reste 5.
        $limits = app(LimitBook::class);
        $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 45]], $this->titular);
        $limits->publish($this->regionDay, $this->titular);

        $response = ($this->validate)($second, [[$this->orange, 10]], version: 1);

        expectProblem($response, 409, 'CLIENT_LIMIT_EXCEEDED');
        $response->assertJsonPath('title', 'Disponible client dépassé')
            ->assertJsonPath('detail', 'La validation dépasserait la borne du client pour 1 produit.')
            ->assertJsonPath('violations', [[
                'line' => 0, 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 10, 'quota' => 100, 'validated' => 40,
                'limit' => 45, 'client_validated' => 40, 'max_allowed' => 5, 'cause' => 'CLIENT_LIMIT',
            ]]);
        expect(($this->counter)($this->orange))->toBe(40);
        // Appliquer le maximum annoncé suffit.
        ($this->validate)($second, [[$this->orange, 5, 'BORNE_ATTEINTE']], version: 1)->assertOk()->assertJsonPath('lines.0.validated_qty', 5);
        expect(($this->counter)($this->orange))->toBe(45);
    });

    it('lists every line in excess with its own cause, REGION_QUOTA_EXCEEDED as soon as one is a region cause', function () {
        // A : l'orange à 40 (commande standard), puis 10 orange et 10 pomme (S + V = 50, sa borne) ; B : 295 pomme.
        $second = ($this->submit)($this->accountA, [[$this->orange, 10], [$this->pomme, 10]]);
        $other = ($this->submit)($this->accountB, [[$this->pomme, 295]]);
        ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]])->assertOk();
        ($this->validate)($other, [[$this->pomme, 295]])->assertOk();
        // Le régional baisse ensuite la borne de A sur l'orange à 45 (≥ V(c,p) = 40) : reste 5.
        $limits = app(LimitBook::class);
        $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 45]], $this->titular);
        $limits->publish($this->regionDay, $this->titular);

        $response = ($this->validate)($second, [[$this->orange, 10], [$this->pomme, 10]], version: 1);

        expectProblem($response, 409, 'REGION_QUOTA_EXCEEDED');
        // Le décompte du texte ne retient que les lignes de cause « quota région ».
        $response->assertJsonPath('detail', 'La validation dépasserait le quota de la région pour 1 produit.')
            ->assertJsonPath('violations', [
                [
                    'line' => 0, 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 10, 'quota' => 100, 'validated' => 40,
                    'limit' => 45, 'client_validated' => 40, 'max_allowed' => 5, 'cause' => 'CLIENT_LIMIT',
                ],
                [
                    'line' => 1, 'product_id' => $this->pomme->id, 'sku' => 'P0057', 'requested' => 10, 'quota' => 300, 'validated' => 298,
                    'limit' => null, 'client_validated' => 3, 'max_allowed' => 2, 'cause' => 'REGION_QUOTA',
                ],
            ]);
        ($this->untouched)($second);
    });

    it('treats a borne at 0 as a maximum of 0, and no borne as no ceiling for the client, even under the REQUIRED policy (I-91)', function () {
        $pending = ($this->submit)($this->accountB, [[$this->pomme, 295]]);
        $limits = app(LimitBook::class);
        $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 0]], $this->titular);
        $limits->publish($this->regionDay, $this->titular);
        ($this->settings)(['limits' => ['no_limit_policy' => 'REQUIRED']]);

        $response = ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1);
        expectProblem($response, 409, 'CLIENT_LIMIT_EXCEEDED');
        $response->assertJsonPath('violations.0.limit', 0)->assertJsonPath('violations.0.max_allowed', 0);

        // B n'a aucune borne : seule la limite de la région compte.
        ($this->validate)($pending, [[$this->pomme, 295]], version: 1)->assertOk();
        expect(($this->counter)($this->pomme))->toBe(295);
    });

    it('keeps validating a product deactivated after the order was sent (I-91)', function () {
        $this->pomme->forceFill(['is_active' => false])->save();

        ($this->validate)($this->order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], version: 1)->assertOk()->assertJsonPath('lines.1.validated_qty', 3);
    });
});
