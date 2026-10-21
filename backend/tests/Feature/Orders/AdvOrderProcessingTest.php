<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderStatus;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrderCancelledByAdv;
use App\Events\OrdersExpired;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Events\OrderSubmitted;
use App\Events\OrderValidated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Http\Problems\ApiException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Region;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// Écritures de la file ADV : POST /adv/orders/bulk-status, PATCH
// /adv/orders/{id}/shipping, POST /adv/orders/{id}/cancel (contrat du
// lot 6, §5) — résultat partiel en masse, règles d'expédition (figée après
// l'expédition, motif d'écart si et seulement si expédié < validé, 0
// permis), annulation (V restitué même après la clôture, refusée dès
// Préparée), erreurs 422 de forme et 409 du service.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderCancelled::class, OrdersExpired::class,
        OrderValidated::class, OrdersProcessed::class, OrderShippingUpdated::class, OrderCancelledByAdv::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->e = Client::factory()->create(['code' => 'C0300', 'region_id' => $this->est->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]]);
    openOrderingDay('2026-10-08', $this->est, [[$this->orange, 200]]);
    app(Settings::class)->update(['orders' => ['max_per_day' => 5]], null);
    $this->adv = userWithRole(Role::Adv, ['name' => 'Samir ADV']);
    $this->accounts = [];
    $this->submit = function (Client $client, array $lines): Order {
        $this->accounts[$client->id] ??= userWithRole(Role::Client, ['client_id' => $client->id]);

        return app(ClientOrders::class)->submit(
            $this->accounts[$client->id], (string) Str::uuid(), BusinessClock::parseDay('2026-10-08'),
            array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines), null,
        )['order'];
    };
    // Commande envoyée puis validée (quantités demandées, sauf indication).
    $this->validated = fn (Client $client, array $lines, array $quantities = []): Order => validateOrder(($this->submit)($client, $lines), $quantities);
    $this->counter = fn (Product $product): int => RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $product->id)->value('validated_qty');
    $this->unknown = fn (): string => strtoupper((string) Str::ulid());
    $this->bulk = fn (string $status, array $orders, ?User $as = null) => $this->actingAs($as ?? $this->adv)->postJson('/api/v1/adv/orders/bulk-status', ['status' => $status, 'orders' => $orders]);
    $this->item = fn (Order $order, ?int $version = null): array => ['id' => $order->public_id, 'version' => $version ?? $order->fresh()->version];
    $this->shipping = fn (Order $order, array $body, ?User $as = null) => $this->actingAs($as ?? $this->adv)->patchJson("/api/v1/adv/orders/{$order->public_id}/shipping", $body);
    $this->cancel = fn (Order $order, array $body, ?User $as = null) => $this->actingAs($as ?? $this->adv)->postJson("/api/v1/adv/orders/{$order->public_id}/cancel", $body);
    // Commande validée, puis amenée au statut demandé par l'ADV.
    $this->at = function (OrderStatus $status, Client $client, array $lines, array $quantities = []): Order {
        $order = ($this->validated)($client, $lines, $quantities);
        foreach ([OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped] as $step) {
            if ($order->fresh()->status === $status) {
                break;
            }
            ($this->bulk)($step->value, [($this->item)($order)])->assertOk()->assertJsonCount(1, 'applied');
        }

        return $order->fresh();
    };
});

describe('POST /adv/orders/bulk-status', function () {
    it('moves the orders one step and reports the refusals one by one, in the order of the request (I-78)', function () {
        $first = ($this->validated)($this->a, [[$this->orange, 10]]);
        $stale = ($this->validated)($this->b, [[$this->orange, 10]]);
        $ahead = ($this->at)(OrderStatus::InPreparation, $this->e, [[$this->orange, 10]]);
        $submitted = ($this->submit)($this->a, [[$this->pomme, 1]]);
        $unknown = ($this->unknown)();

        $response = ($this->bulk)('IN_PREPARATION', [
            ($this->item)($first),
            ($this->item)($stale, 1),
            ($this->item)($ahead),
            ($this->item)($submitted),
            ['id' => $unknown, 'version' => 1],
        ])->assertOk();

        expect($response->json())->toBe([
            'applied' => [['id' => $first->public_id, 'number' => $first->number, 'status' => 'IN_PREPARATION', 'version' => 3]],
            'refused' => [
                ['id' => $stale->public_id, 'number' => $stale->number, 'code' => 'VERSION_CONFLICT', 'detail' => 'La commande a été modifiée entre-temps : rechargez-la.', 'version' => 2],
                ['id' => $ahead->public_id, 'number' => $ahead->number, 'code' => 'INVALID_TRANSITION', 'detail' => 'Commande « En préparation » : impossible de passer à « En préparation ».', 'version' => 3],
                // Une commande envoyée n'est jamais dans la file (ni verrouillée) ; une inconnue non plus.
                ['id' => $submitted->public_id, 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
                ['id' => $unknown, 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
            ],
        ])
            ->and($first->fresh()->status)->toBe(OrderStatus::InPreparation)
            ->and($stale->fresh()->status)->toBe(OrderStatus::Validated)
            ->and($submitted->fresh()->status)->toBe(OrderStatus::Submitted);
    });

    it('stamps the processing time, records the ADV in the history and sends one event for the whole change', function () {
        $one = ($this->validated)($this->a, [[$this->orange, 10]]);
        $two = ($this->validated)($this->e, [[$this->orange, 10]]);
        Event::assertNotDispatched(OrdersProcessed::class);

        ($this->bulk)('IN_PREPARATION', [($this->item)($one), ($this->item)($two)])->assertOk()->assertJsonCount(2, 'applied')->assertJsonCount(0, 'refused');

        expect($one->fresh()->prep_started_at->equalTo(now()))->toBeTrue()
            ->and($one->history()->get()->last()->only(['from_status', 'to_status', 'version', 'user_id', 'actor']))->toBe([
                'from_status' => OrderStatus::Validated, 'to_status' => OrderStatus::InPreparation, 'version' => 3,
                'user_id' => $this->adv->id, 'actor' => OrderActor::Adv,
            ])
            ->and(AuditLog::query()->where('event', 'status_changed')->count())->toBe(2);
        // Un seul événement pour les deux régions (I-97) ; l'écran se relit une fois.
        Event::assertDispatchedTimes(OrdersProcessed::class, 1);
        Event::assertDispatched(OrdersProcessed::class, fn (OrdersProcessed $e) => $e->to === OrderStatus::InPreparation && count($e->orders) === 2 && $e->actorId === $this->adv->id);
    });

    it('takes the validated quantity as the shipped one at PREPARED and SHIPPED, whatever the step goes through (I-77)', function () {
        $order = ($this->validated)($this->a, [[$this->orange, 40], [$this->pomme, 5]], [$this->pomme->id => 0]);
        ($this->bulk)('IN_PREPARATION', [($this->item)($order)])->assertOk();
        expect($order->lines()->pluck('shipped_qty')->all())->toBe([null, null]);

        $prepared = ($this->bulk)('PREPARED', [($this->item)($order)])->assertOk();
        expect($prepared->json('applied.0'))->toMatchArray(['status' => 'PREPARED', 'version' => 4])
            ->and($order->lines()->pluck('shipped_qty', 'product_id')->all())->toEqual([$this->orange->id => 40, $this->pomme->id => 0])
            ->and($order->fresh()->prepared_at->equalTo(now()))->toBeTrue();

        $this->travel(10)->minutes();
        $shipped = ($this->bulk)('SHIPPED', [($this->item)($order)])->assertOk();
        expect($shipped->json('applied.0'))->toMatchArray(['status' => 'SHIPPED', 'version' => 5])
            ->and($order->fresh()->shipped_at->equalTo(now()))->toBeTrue()
            // Le quota n'est jamais touché par le traitement (Q-16).
            ->and(($this->counter)($this->orange))->toBe(40);
    });

    it('keeps a quantity typed while in preparation when the order goes to PREPARED', function () {
        $order = ($this->validated)($this->a, [[$this->orange, 40]]);
        ($this->bulk)('IN_PREPARATION', [($this->item)($order)])->assertOk();
        ($this->shipping)($order, ['version' => 3, 'lines' => [['product_id' => $this->orange->id, 'shipped_qty' => 38, 'reason_code' => 'CASSE']]])->assertOk();

        ($this->bulk)('PREPARED', [($this->item)($order)])->assertOk();

        expect($order->lines()->first()->only(['validated_qty', 'shipped_qty', 'shipping_gap_reason_code']))->toBe(['validated_qty' => 40, 'shipped_qty' => 38, 'shipping_gap_reason_code' => 'CASSE']);
    });

    it('goes one step at a time: the other steps are refused per order, never 422', function () {
        $order = ($this->validated)($this->a, [[$this->orange, 10]]);

        foreach (['PREPARED', 'SHIPPED'] as $status) {
            $response = ($this->bulk)($status, [($this->item)($order)])->assertOk();
            expect($response->json('applied'))->toBe([])
                ->and($response->json('refused.0.code'))->toBe('INVALID_TRANSITION');
        }
        expect($order->fresh()->status)->toBe(OrderStatus::Validated);
    });

    it('treats a renewed request as applied, without a second history line nor event', function () {
        $order = ($this->validated)($this->a, [[$this->orange, 10]]);
        $request = [($this->item)($order, 2)];

        $first = ($this->bulk)('IN_PREPARATION', $request)->assertOk()->json();
        $again = ($this->bulk)('IN_PREPARATION', $request)->assertOk()->json();

        expect($again)->toBe($first)
            ->and($first['applied'][0]['version'])->toBe(3)
            ->and(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(3);
        Event::assertDispatchedTimes(OrdersProcessed::class, 1);
    });

    it('refuses an order cancelled after its validation and keeps the other items going', function () {
        $cancelled = ($this->validated)($this->a, [[$this->orange, 10]]);
        $kept = ($this->validated)($this->b, [[$this->orange, 10]]);
        ($this->cancel)($cancelled, ['version' => 2, 'reason_code' => 'RUPTURE'])->assertOk();

        $response = ($this->bulk)('IN_PREPARATION', [($this->item)($cancelled), ($this->item)($kept)])->assertOk();

        expect($response->json('refused.0'))->toMatchArray(['id' => $cancelled->public_id, 'code' => 'INVALID_TRANSITION', 'version' => 3])
            ->and($response->json('applied.*.id'))->toBe([$kept->public_id]);
    });

    it('accepts 100 orders and refuses 101 (I-78)', function () {
        $items = fn (int $count): array => array_map(fn () => ['id' => ($this->unknown)(), 'version' => 1], range(1, $count));

        $max = ($this->bulk)('IN_PREPARATION', $items(100))->assertOk();
        expect($max->json('refused'))->toHaveCount(100)
            ->and(collect($max->json('refused'))->pluck('code')->unique()->all())->toBe(['NOT_FOUND']);

        $response = ($this->bulk)('IN_PREPARATION', $items(101));
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors.orders.0', 'Au plus 100 commandes à la fois.');
    });

    it('answers 422 to a status that is not a processing step: Delivered, Cancelled, unknown, missing', function (mixed $status) {
        $order = ($this->validated)($this->a, [[$this->orange, 10]]);
        $body = ['orders' => [($this->item)($order)]] + ($status === null ? [] : ['status' => $status]);

        $response = $this->actingAs($this->adv)->postJson('/api/v1/adv/orders/bulk-status', $body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['status']]);
        expect($order->fresh()->status)->toBe(OrderStatus::Validated);
    })->with(['DELIVERED', 'CANCELLED', 'VALIDATED', 'SUBMITTED', 'in_preparation', 'BOGUS', [['IN_PREPARATION']], 42, null]);

    it('answers 422 to the shape of the orders', function (array $body, string $field) {
        $order = ($this->validated)($this->a, [[$this->orange, 10]]);
        $body = array_replace_recursive(['status' => 'IN_PREPARATION'], $body);

        $response = $this->actingAs($this->adv)->postJson('/api/v1/adv/orders/bulk-status', $body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        expect($order->fresh()->status)->toBe(OrderStatus::Validated);
    })->with([
        'sans commande' => [[], 'orders'],
        'liste vide' => [['orders' => []], 'orders'],
        'pas une liste' => [['orders' => 'x'], 'orders'],
        'objet au lieu de liste' => [['orders' => ['a' => ['id' => '01JA2Z6K8QW3X9M4T7RBN5CDEF', 'version' => 1]]], 'orders'],
        'élément vide' => [['orders' => ['x']], 'orders.0'],
        'identifiant absent' => [['orders' => [['version' => 1]]], 'orders.0.id'],
        'identifiant mal formé' => [['orders' => [['id' => 'not-an-ulid', 'version' => 1]]], 'orders.0.id'],
        'numéro de commande' => [['orders' => [['id' => 'RMK-261008-00001', 'version' => 1]]], 'orders.0.id'],
        'version absente' => [['orders' => [['id' => '01JA2Z6K8QW3X9M4T7RBN5CDEF']]], 'orders.0.version'],
        'version texte' => [['orders' => [['id' => '01JA2Z6K8QW3X9M4T7RBN5CDEF', 'version' => '2']]], 'orders.0.version'],
        'version à zéro' => [['orders' => [['id' => '01JA2Z6K8QW3X9M4T7RBN5CDEF', 'version' => 0]]], 'orders.0.version'],
        'version décimale' => [['orders' => [['id' => '01JA2Z6K8QW3X9M4T7RBN5CDEF', 'version' => 1.5]]], 'orders.0.version'],
    ]);

    it('answers 422 to a duplicate order, whatever the case of its id, on the second occurrence (I-56)', function () {
        $order = ($this->validated)($this->a, [[$this->orange, 10]]);

        $response = ($this->bulk)('IN_PREPARATION', [($this->item)($order), ($this->item)($order), ['id' => strtolower($order->public_id), 'version' => 2]]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['orders.1.id', 'orders.2.id']])->assertJsonMissingPath('errors.orders.0.id');
        expect($order->fresh()->status)->toBe(OrderStatus::Validated);
    });
});

describe('PATCH /adv/orders/{id}/shipping', function () {
    beforeEach(function () {
        // Deux lignes : orange 40, pomme 5 ; en préparation (version 3).
        $this->order = ($this->at)(OrderStatus::InPreparation, $this->a, [[$this->orange, 40], [$this->pomme, 5]]);
        $this->line = fn (int $productId, int $shipped, ?string $reason = null): array => ['product_id' => $productId, 'shipped_qty' => $shipped] + ($reason === null ? [] : ['reason_code' => $reason]);
        $this->shippedOf = fn (Order $order): array => $order->lines()->get()->mapWithKeys(fn ($line) => [$line->product_id => [$line->shipped_qty, $line->shipping_gap_reason_code]])->all();
    });

    it('records the shipped quantities, the gap reasons and the delivery note, and answers with the ADV detail (RG-A03, I-79)', function () {
        $response = ($this->shipping)($this->order, [
            'version' => 3,
            'delivery_note_ref' => ' BL-0042 ',
            'lines' => [($this->line)($this->orange->id, 38, 'CASSE'), ($this->line)($this->pomme->id, 5)],
        ])->assertOk();

        expect($response->json())->toMatchArray([
            'id' => $this->order->public_id, 'status' => 'IN_PREPARATION', 'version' => 4, 'delivery_note_ref' => 'BL-0042', 'has_shipping_gap' => true,
            'can' => ['next_statuses' => ['PREPARED'], 'edit_shipping_lines' => true, 'edit_delivery_note' => true, 'cancel' => true, 'print' => true],
        ])
            ->and($response->json('lines.0'))->toMatchArray(['shipped_qty' => 38, 'shipping_gap_reason' => ['code' => 'CASSE', 'label' => 'Casse']])
            ->and($response->json('lines.1'))->toMatchArray(['shipped_qty' => 5, 'shipping_gap_reason' => null])
            // Pas de ligne d'historique : ce n'est pas un changement de statut (I-79).
            ->and($response->json('history.*.to'))->toBe(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION'])
            ->and(($this->shippedOf)($this->order))->toBe([$this->orange->id => [38, 'CASSE'], $this->pomme->id => [5, null]])
            // Le quota n'est jamais touché par l'expédition (RG-A03, Q-16).
            ->and(($this->counter)($this->orange))->toBe(40);
        Event::assertDispatched(OrderShippingUpdated::class, fn (OrderShippingUpdated $e) => $e->orderId === $this->order->id && $e->version === 4 && $e->actorId === $this->adv->id);
        expect(AuditLog::query()->where('event', 'shipping_updated')->sole()->properties['delivery_note_ref'])->toMatchArray(['before' => null, 'after' => 'BL-0042'])->toHaveCount(2);
    });

    it('changes only the lines sent, and the delivery note alone', function () {
        ($this->shipping)($this->order, ['version' => 3, 'lines' => [($this->line)($this->pomme->id, 2, 'RUPTURE')]])->assertOk()->assertJsonPath('version', 4);
        ($this->shipping)($this->order, ['version' => 4, 'delivery_note_ref' => 'BL-1'])->assertOk()->assertJsonPath('version', 5);

        expect(($this->shippedOf)($this->order))->toBe([$this->orange->id => [null, null], $this->pomme->id => [2, 'RUPTURE']])
            ->and($this->order->fresh()->delivery_note_ref)->toBe('BL-1');
    });

    it('allows 0 shipped with a reason, on every line, and a gap reason only when the shipped quantity is below the validated one (RG-A03, I-77)', function () {
        $response = ($this->shipping)($this->order, ['version' => 3, 'lines' => [($this->line)($this->orange->id, 0, 'RUPTURE'), ($this->line)($this->pomme->id, 0, 'RUPTURE')]])->assertOk();

        expect(($this->shippedOf)($this->order))->toBe([$this->orange->id => [0, 'RUPTURE'], $this->pomme->id => [0, 'RUPTURE']])
            ->and($response->json('has_shipping_gap'))->toBeTrue();

        // Retour à la quantité validée : le motif disparaît avec l'écart.
        $back = ($this->shipping)($this->order, ['version' => 4, 'lines' => [($this->line)($this->orange->id, 40)]])->assertOk();
        expect(($this->shippedOf)($this->order)[$this->orange->id])->toBe([40, null])
            ->and($back->json('lines.0.shipping_gap_reason'))->toBeNull();
    });

    it('lets a line validated at 0 ship 0 without any reason', function () {
        $order = ($this->at)(OrderStatus::InPreparation, $this->b, [[$this->orange, 10], [$this->pomme, 5]], [$this->pomme->id => 0]);

        ($this->shipping)($order, ['version' => 3, 'lines' => [($this->line)($this->pomme->id, 0)]])->assertOk();
        $response = ($this->shipping)($order, ['version' => 3, 'lines' => [($this->line)($this->pomme->id, 1)]]);

        expectProblem($response, 409, 'VERSION_CONFLICT');
        $response = ($this->shipping)($order, ['version' => 4, 'lines' => [($this->line)($this->pomme->id, 1)]]);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['lines.0.shipped_qty']]);
    });

    it('answers 422 to a quantity above the validated one, a missing, a prohibited or an unknown gap reason, an unknown or repeated product', function () {
        $response = ($this->shipping)($this->order, ['version' => 3, 'lines' => [
            ($this->line)($this->orange->id, 41),
            ($this->line)($this->pomme->id, 4),
        ]]);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect($response->json('errors'))->toBe([
            'lines.0.shipped_qty' => ['La quantité expédiée doit être un nombre entier de 0 à 40 (quantité validée).'],
            'lines.1.reason_code' => ['Motif d\'écart obligatoire quand la quantité expédiée est inférieure à la quantité validée.'],
        ]);

        $response = ($this->shipping)($this->order, ['version' => 3, 'lines' => [
            ($this->line)($this->orange->id, 40, 'RUPTURE'),
            ($this->line)($this->pomme->id, 5),
            ($this->line)($this->pomme->id, 4, 'CASSE'),
            ($this->line)(999_999, 1),
        ]]);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect($response->json('errors'))->toBe([
            'lines.0.reason_code' => ['Pas de motif d\'écart quand toute la quantité validée est expédiée.'],
            'lines.2.product_id' => ['Ce produit figure deux fois.'],
            'lines.3.product_id' => ['Ce produit ne figure pas dans la commande.'],
        ]);
        expect(($this->shippedOf)($this->order))->toBe([$this->orange->id => [null, null], $this->pomme->id => [null, null]])
            ->and($this->order->fresh()->version)->toBe(3);
        Event::assertNotDispatched(OrderShippingUpdated::class);
    });

    it('answers 422 to a gap reason that is unknown, of another type or deactivated', function (string $code) {
        ReasonCode::query()->where('type', 'SHIPPING_GAP')->where('code', 'ERREUR_PREPARATION')->update(['is_active' => false]);
        app(ReasonCodes::class)->flush();

        $response = ($this->shipping)($this->order, ['version' => 3, 'lines' => [($this->line)($this->pomme->id, 4, $code)]]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect($response->json('errors'))->toBe(['lines.0.reason_code' => ['Motif inconnu ou désactivé : choisissez un motif de la liste.']]);
    })->with(['BOGUS', 'QUOTA_INSUFFISANT', 'ERREUR_PREPARATION', 'rupture']);

    it('answers 422 to a delivery note longer than 50 characters and to a malformed body', function (array $body, string $field) {
        $response = ($this->shipping)($this->order, $body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        expect($this->order->fresh()->version)->toBe(3);
    })->with([
        'bon de livraison trop long' => [['version' => 3, 'delivery_note_ref' => str_repeat('B', 51)], 'delivery_note_ref'],
        'bon de livraison en tableau' => [['version' => 3, 'delivery_note_ref' => ['BL']], 'delivery_note_ref'],
        'sans version' => [['delivery_note_ref' => 'BL-1'], 'version'],
        'version texte' => [['version' => '3'], 'version'],
        'version à zéro' => [['version' => 0], 'version'],
        'lignes en texte' => [['version' => 3, 'lines' => 'x'], 'lines'],
        'ligne sans produit' => [['version' => 3, 'lines' => [['shipped_qty' => 1]]], 'lines.0.product_id'],
        'produit texte' => [['version' => 3, 'lines' => [['product_id' => '1', 'shipped_qty' => 1]]], 'lines.0.product_id'],
        'ligne sans quantité' => [['version' => 3, 'lines' => [['product_id' => 1]]], 'lines.0.shipped_qty'],
        'quantité négative' => [['version' => 3, 'lines' => [['product_id' => 1, 'shipped_qty' => -1]]], 'lines.0.shipped_qty'],
        'quantité texte' => [['version' => 3, 'lines' => [['product_id' => 1, 'shipped_qty' => '1']]], 'lines.0.shipped_qty'],
        'quantité décimale' => [['version' => 3, 'lines' => [['product_id' => 1, 'shipped_qty' => 1.5]]], 'lines.0.shipped_qty'],
        'quantité énorme' => [['version' => 3, 'lines' => [['product_id' => 1, 'shipped_qty' => 1_000_001]]], 'lines.0.shipped_qty'],
        'motif en tableau' => [['version' => 3, 'lines' => [['product_id' => 1, 'shipped_qty' => 1, 'reason_code' => ['RUPTURE']]]], 'lines.0.reason_code'],
    ]);

    it('freezes the quantities once shipped: only the delivery note changes, and null or blank clears it (I-77)', function () {
        $order = ($this->at)(OrderStatus::Shipped, $this->b, [[$this->orange, 10]]);
        expect($order->version)->toBe(5);

        $frozen = ($this->shipping)($order, ['version' => 5, 'lines' => [($this->line)($this->orange->id, 9, 'CASSE')]]);
        expectProblem($frozen, 409, 'INVALID_TRANSITION');
        $frozen->assertJsonPath('detail', 'Commande expédiée : les quantités ne changent plus, seul le bon de livraison peut être modifié.');

        $set = ($this->shipping)($order, ['version' => 5, 'delivery_note_ref' => 'BL-0099'])->assertOk();
        expect($set->json())->toMatchArray(['status' => 'SHIPPED', 'version' => 6, 'delivery_note_ref' => 'BL-0099'])
            ->and($set->json('can.edit_shipping_lines'))->toBeFalse()
            ->and($set->json('can.edit_delivery_note'))->toBeTrue();
        ($this->shipping)($order, ['version' => 6, 'delivery_note_ref' => null])->assertOk()->assertJsonPath('delivery_note_ref', null)->assertJsonPath('version', 7);
        ($this->shipping)($order, ['version' => 7, 'delivery_note_ref' => 'BL-3'])->assertOk();
        ($this->shipping)($order, ['version' => 8, 'delivery_note_ref' => '  '])->assertOk()->assertJsonPath('delivery_note_ref', null);
        expect($order->lines()->first()->shipped_qty)->toBe(10);
    });

    it('returns the order as is, without a new version nor event, when nothing changes', function () {
        ($this->shipping)($this->order, ['version' => 3, 'delivery_note_ref' => 'BL-1', 'lines' => [($this->line)($this->pomme->id, 4, 'CASSE')]])->assertOk()->assertJsonPath('version', 4);
        Event::assertDispatchedTimes(OrderShippingUpdated::class, 1);

        ($this->shipping)($this->order, ['version' => 4, 'delivery_note_ref' => 'BL-1', 'lines' => [($this->line)($this->pomme->id, 4, 'CASSE')]])->assertOk()->assertJsonPath('version', 4);
        ($this->shipping)($this->order, ['version' => 4])->assertOk()->assertJsonPath('version', 4);

        Event::assertDispatchedTimes(OrderShippingUpdated::class, 1);
    });

    it('is allowed while prepared, and refused 409 before preparation and once cancelled', function () {
        $prepared = ($this->at)(OrderStatus::Prepared, $this->b, [[$this->orange, 10]]);
        ($this->shipping)($prepared, ['version' => 4, 'lines' => [($this->line)($this->orange->id, 8, 'CASSE')]])->assertOk()->assertJsonPath('lines.0.shipped_qty', 8);

        $validated = ($this->validated)($this->b, [[$this->orange, 10]]);
        expectProblem(($this->shipping)($validated, ['version' => 2, 'delivery_note_ref' => 'BL-1']), 409, 'INVALID_TRANSITION');

        ($this->cancel)($validated, ['version' => 2, 'reason_code' => 'RUPTURE'])->assertOk();
        expectProblem(($this->shipping)($validated, ['version' => 3, 'delivery_note_ref' => 'BL-1']), 409, 'INVALID_TRANSITION');
    });

    it('answers 409 VERSION_CONFLICT with the current version, and nothing is written', function () {
        $response = ($this->shipping)($this->order, ['version' => 2, 'delivery_note_ref' => 'BL-1', 'lines' => [($this->line)($this->pomme->id, 4, 'CASSE')]]);

        expectProblem($response, 409, 'VERSION_CONFLICT');
        $response->assertJsonPath('version', 3);
        expect($this->order->fresh()->delivery_note_ref)->toBeNull()
            ->and(($this->shippedOf)($this->order)[$this->pomme->id])->toBe([null, null]);
    });

    it('answers a status refusal before a stale version, the shape before both (I-68)', function () {
        $validated = ($this->validated)($this->b, [[$this->orange, 10]]);

        expectProblem(($this->shipping)($validated, ['version' => 99, 'delivery_note_ref' => 'BL-1']), 409, 'INVALID_TRANSITION');
        expectProblem(($this->shipping)($validated, ['version' => 'x']), 422, 'VALIDATION_FAILED');
    });
});

describe('POST /adv/orders/{id}/cancel', function () {
    beforeEach(function () {
        $this->order = ($this->validated)($this->a, [[$this->orange, 40], [$this->pomme, 5]], [$this->pomme->id => 0]);
        $this->kept = ($this->validated)($this->b, [[$this->orange, 30]]);
    });

    it('cancels a validated order: reason and comment of the cancellation, V given back, ADV in the history (RG-V07, T08, I-75)', function () {
        $decidedAt = $this->order->decided_at;
        expect(($this->counter)($this->orange))->toBe(70);

        $response = ($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'RUPTURE', 'comment' => ' Plus de stock '])->assertOk();

        expect($response->json())->toMatchArray([
            'id' => $this->order->public_id,
            'status' => 'CANCELLED',
            'status_label' => 'Annulée',
            'version' => 3,
            'next_statuses' => [],
            'can_cancel' => false,
            'decision_reason' => ['code' => 'RUPTURE', 'label' => 'Rupture de stock'],
            'decision_comment' => 'Plus de stock',
            'can' => ['next_statuses' => [], 'edit_shipping_lines' => false, 'edit_delivery_note' => false, 'cancel' => false, 'print' => true],
        ])
            // La validation reste ; quantités validées et totaux gardés (I-74, I-75).
            ->and($response->json('decided_at'))->toBe($decidedAt->toIso8601String())
            ->and($response->json('lines.*.validated_qty'))->toBe([40, 0])
            ->and($response->json('total_ht'))->toEqual(36000)
            ->and($response->json('history.*.to'))->toBe(['SUBMITTED', 'VALIDATED', 'CANCELLED'])
            ->and($response->json('history.2'))->toMatchArray(['actor' => 'ADV', 'by_name' => 'Samir ADV', 'comment' => 'Plus de stock', 'version' => 3])
            // V(r,p) restitué ; l'autre commande garde le sien.
            ->and(($this->counter)($this->orange))->toBe(30);
        Event::assertDispatched(OrderCancelledByAdv::class, fn (OrderCancelledByAdv $e) => $e->orderId === $this->order->id && $e->clientId === $this->a->id && $e->version === 3 && $e->actorId === $this->adv->id);
        Event::assertNotDispatched(OrderCancelled::class);
        expect(AuditLog::query()->where('event', 'cancelled_by_adv')->sole()->properties['released'])->sequence(fn ($released) => $released->toMatchArray(['sku' => 'P0042', 'qty' => 40])->toHaveCount(2));
    });

    it('cancels from IN_PREPARATION as well', function () {
        $inPreparation = ($this->at)(OrderStatus::InPreparation, $this->e, [[$this->orange, 20]]);

        ($this->cancel)($inPreparation, ['version' => 3, 'reason_code' => 'DEMANDE_CLIENT'])->assertOk()->assertJsonPath('status', 'CANCELLED');
    });

    it('cancels after the closing of the day and still gives V back (H-06, I-76)', function () {
        app(DayCalendar::class)->close($this->regionDay->businessDay);
        expect(($this->counter)($this->orange))->toBe(70);

        ($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'RUPTURE'])->assertOk()->assertJsonPath('status', 'CANCELLED');

        expect(($this->counter)($this->orange))->toBe(30);
    });

    it('refuses with 409 an order prepared, shipped or already cancelled by someone else (INVALID_TRANSITION)', function (OrderStatus $status) {
        $order = ($this->at)($status, $this->e, [[$this->orange, 20]]);
        $before = ($this->counter)($this->orange);

        $response = ($this->cancel)($order, ['version' => $order->version, 'reason_code' => 'RUPTURE']);

        expectProblem($response, 409, 'INVALID_TRANSITION');
        $response->assertJsonPath('detail', "Commande « {$status->label()} » : impossible de passer à « Annulée ».");
        expect($order->fresh()->status)->toBe($status)
            ->and(($this->counter)($this->orange))->toBe($before);
        Event::assertNotDispatched(OrderCancelledByAdv::class);
    })->with([OrderStatus::Prepared, OrderStatus::Shipped]);

    it('answers 409 VERSION_CONFLICT with the current version, V untouched', function () {
        $response = ($this->cancel)($this->order, ['version' => 1, 'reason_code' => 'RUPTURE']);

        expectProblem($response, 409, 'VERSION_CONFLICT');
        $response->assertJsonPath('version', 2);
        expect($this->order->fresh()->status)->toBe(OrderStatus::Validated)
            ->and(($this->counter)($this->orange))->toBe(70);
    });

    it('answers a status refusal before a stale version, the shape before both', function () {
        $prepared = ($this->at)(OrderStatus::Prepared, $this->e, [[$this->orange, 20]]);

        expectProblem(($this->cancel)($prepared, ['version' => 99, 'reason_code' => 'RUPTURE']), 409, 'INVALID_TRANSITION');
        expectProblem(($this->cancel)($prepared, ['version' => 99]), 422, 'VALIDATION_FAILED');
    });

    it('treats a renewed cancellation by the same author as done, and refuses another ADV', function () {
        ($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'RUPTURE'])->assertOk();

        ($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'RUPTURE'])->assertOk()->assertJsonPath('version', 3);
        expect(($this->counter)($this->orange))->toBe(30);
        Event::assertDispatchedTimes(OrderCancelledByAdv::class, 1);

        forgetGuards();
        $other = userWithRole(Role::Adv);
        expectProblem(($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'RUPTURE'], $other), 409, 'INVALID_TRANSITION');
    });

    it('answers 422 to a missing, unknown, deactivated or foreign-type reason, and to a missing comment when the reason demands one', function (array $body, string $field) {
        ReasonCode::query()->where('type', 'CANCELLATION')->where('code', 'ERREUR_SAISIE')->update(['is_active' => false]);
        app(ReasonCodes::class)->flush();

        $response = ($this->cancel)($this->order, $body + ['version' => 2]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        expect($this->order->fresh()->status)->toBe(OrderStatus::Validated)
            ->and(($this->counter)($this->orange))->toBe(70);
    })->with([
        'sans motif' => [[], 'reason_code'],
        'motif vide' => [['reason_code' => ''], 'reason_code'],
        'motif inconnu' => [['reason_code' => 'BOGUS'], 'reason_code'],
        'motif d\'un autre type' => [['reason_code' => 'QUOTA_INSUFFISANT'], 'reason_code'],
        'motif désactivé' => [['reason_code' => 'ERREUR_SAISIE'], 'reason_code'],
        'motif en tableau' => [['reason_code' => ['RUPTURE']], 'reason_code'],
        'commentaire obligatoire' => [['reason_code' => 'AUTRE'], 'comment'],
        'commentaire trop long' => [['reason_code' => 'RUPTURE', 'comment' => str_repeat('x', 501)], 'comment'],
        'commentaire en tableau' => [['reason_code' => 'RUPTURE', 'comment' => ['x']], 'comment'],
        'version texte' => [['reason_code' => 'RUPTURE', 'version' => '2'], 'version'],
        'version à zéro' => [['reason_code' => 'RUPTURE', 'version' => 0], 'version'],
    ]);

    it('accepts the reason that demands a comment when it comes with one', function () {
        $response = ($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'AUTRE', 'comment' => 'Camion en panne'])->assertOk();

        expect($response->json('decision_reason'))->toBe(['code' => 'AUTRE', 'label' => 'Autre'])
            ->and($response->json('decision_comment'))->toBe('Camion en panne');
    });

    it('frees the slot of the client for the day: it can send an order again (I-94)', function () {
        app(Settings::class)->update(['orders' => ['max_per_day' => 1]], null);
        expect(fn () => ($this->submit)($this->a, [[$this->orange, 5]]))->toThrow(ApiException::class);

        ($this->cancel)($this->order, ['version' => 2, 'reason_code' => 'DEMANDE_CLIENT'])->assertOk();

        expect(($this->submit)($this->a, [[$this->orange, 5]])->status)->toBe(OrderStatus::Submitted);
    });
});
