<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderUsage;
use App\Domain\Referentials\AssignmentKind;
use App\Events\DayClosed;
use App\Events\OrderCancelled;
use App\Events\OrderCancelledByAdv;
use App\Events\OrdersExpired;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Events\OrderSubmitted;
use App\Events\OrderValidated;
use App\Http\Problems\ApiException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Traitement des commandes validées par l'ADV (§5.6, §6.3 ; contrat du
// lot 6, §5) : statuts unitaires ou en masse avec résultat partiel,
// expédition, annulation exceptionnelle (V restitué), périmètre RG-S05.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        OrderSubmitted::class, OrderCancelled::class, OrdersExpired::class, OrderValidated::class, DayClosed::class,
        OrdersProcessed::class, OrderShippingUpdated::class, OrderCancelledByAdv::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->e = Client::factory()->create(['code' => 'C0090', 'region_id' => $this->est->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 100], [$this->pomme, 300]]);
    $this->estDay = openOrderingDay('2026-10-08', $this->est, [[$this->orange, 100]]);
    $this->adv = userWithRole(Role::Adv);
    $this->orders = app(AdvOrders::class);
    $this->accounts = [];
    $this->submit = function (Client $client, array $lines): Order {
        $this->accounts[$client->id] ??= userWithRole(Role::Client, ['client_id' => $client->id]);

        return app(ClientOrders::class)->submit(
            $this->accounts[$client->id],
            (string) Str::uuid(),
            BusinessClock::parseDay('2026-10-08'),
            array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            null,
        )['order'];
    };
    // Commande envoyée puis validée (quantités demandées, sauf indication).
    $this->validated = fn (Client $client, array $lines, array $quantities = []): Order => validateOrder(($this->submit)($client, $lines), $quantities);
    $this->move = fn (OrderStatus $to, array $orders) => $this->orders->transition(
        $this->adv,
        $to,
        array_map(fn (Order|array $order) => is_array($order) ? $order : ['id' => $order->public_id, 'version' => $order->fresh()->version], $orders),
    );
    $this->counter = fn (Product $product): int => RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $product->id)->value('validated_qty');
    $this->problem = function (Closure $call): ApiException {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }
        throw new RuntimeException('ApiException attendue');
    };
    $this->invalid = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        throw new RuntimeException('ValidationException attendue');
    };
});

it('moves an order to the next processing status: timestamp, history, journal with a batch, one event (RG-A02)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40]]);

    $result = ($this->move)(OrderStatus::InPreparation, [$order]);

    expect($result->toArray())->toBe([
        'applied' => [['id' => $order->public_id, 'number' => $order->number, 'status' => 'IN_PREPARATION', 'version' => 3]],
        'refused' => [],
    ]);
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::InPreparation)
        ->and($order->prep_started_at->equalTo(now()))->toBeTrue()
        ->and($order->history()->get()->last()->only(['from_status', 'to_status', 'version', 'user_id', 'actor']))->toBe([
            'from_status' => OrderStatus::Validated, 'to_status' => OrderStatus::InPreparation, 'version' => 3,
            'user_id' => $this->adv->id, 'actor' => OrderActor::Adv,
        ]);
    $audit = AuditLog::query()->where('log_name', 'orders')->where('event', 'status_changed')->sole();
    expect($audit->causer_id)->toBe($this->adv->id)
        ->and($audit->properties->except('batch')->all())->toEqual(['number' => $order->number, 'from' => 'VALIDATED', 'to' => 'IN_PREPARATION', 'version' => 3])
        ->and($audit->properties['batch'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    Event::assertDispatched(OrdersProcessed::class, fn (OrdersProcessed $e) => $e->to === OrderStatus::InPreparation
        && $e->actorId === $this->adv->id
        && $e->batch === $audit->properties['batch']
        && $e->orders === [['orderId' => $order->id, 'clientId' => $this->a->id, 'regionId' => $this->centre->id, 'businessDayId' => $order->business_day_id, 'version' => 3]]);
});

it('applies a bulk change partially, every refusal with its reason, in the order of the request (I-78)', function () {
    $first = ($this->validated)($this->a, [[$this->orange, 10]]);
    $stale = ($this->validated)($this->b, [[$this->orange, 10]]);
    $ahead = ($this->validated)($this->e, [[$this->orange, 10]]);
    ($this->move)(OrderStatus::InPreparation, [$ahead]);
    $submitted = ($this->submit)(Client::factory()->create(['region_id' => $this->centre->id]), [[$this->pomme, 1]]);
    $unknown = strtoupper((string) Str::ulid());

    $result = ($this->move)(OrderStatus::InPreparation, [
        $first,
        ['id' => $stale->public_id, 'version' => 1],
        $ahead,
        $submitted,
        ['id' => $unknown, 'version' => 1],
        ['id' => 'pas-un-ulid', 'version' => 1],
        ['id' => strtolower($first->public_id), 'version' => 2],
    ]);

    expect($result->applied)->toBe([['id' => $first->public_id, 'number' => $first->number, 'status' => 'IN_PREPARATION', 'version' => 3]])
        ->and($result->refused)->toBe([
            ['id' => $stale->public_id, 'number' => $stale->number, 'code' => 'VERSION_CONFLICT', 'detail' => 'La commande a été modifiée entre-temps : rechargez-la.', 'version' => 2],
            ['id' => $ahead->public_id, 'number' => $ahead->number, 'code' => 'INVALID_TRANSITION', 'detail' => 'Commande « En préparation » : impossible de passer à « En préparation ».', 'version' => 3],
            // Une commande envoyée n'est jamais dans la file de l'ADV (ni verrouillée).
            ['id' => $submitted->public_id, 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
            ['id' => $unknown, 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
            ['id' => 'pas-un-ulid', 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
        ])
        ->and($stale->fresh()->status)->toBe(OrderStatus::Validated)
        ->and($submitted->fresh()->status)->toBe(OrderStatus::Submitted);
    Event::assertDispatchedTimes(OrdersProcessed::class, 2);

    // Renvoi de la même demande : déjà appliquée, sans nouvel historique ni événement.
    $again = ($this->move)(OrderStatus::InPreparation, [['id' => $first->public_id, 'version' => 2]]);
    expect($again->applied)->toBe([['id' => $first->public_id, 'number' => $first->number, 'status' => 'IN_PREPARATION', 'version' => 3]])
        ->and(OrderStatusHistory::query()->where('order_id', $first->id)->count())->toBe(3);
    Event::assertDispatchedTimes(OrdersProcessed::class, 2);
});

it('goes one step at a time, sets the shipped quantity at PREPARED and SHIPPED, and refuses DELIVERED (I-77, I-78)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40], [$this->pomme, 5]], [$this->pomme->id => 0]);

    expect(($this->move)(OrderStatus::Shipped, [$order])->refused[0]['code'])->toBe('INVALID_TRANSITION')
        ->and(($this->invalid)(fn () => ($this->move)(OrderStatus::Delivered, [$order])))->toBe(['status' => ['Statut demandé non autorisé : En préparation, Préparée ou Expédiée seulement.']])
        ->and(($this->invalid)(fn () => ($this->move)(OrderStatus::Cancelled, [$order])))->toHaveKey('status');

    ($this->move)(OrderStatus::InPreparation, [$order]);
    // Saisie partielle en préparation : gardée au passage à Préparée.
    $this->orders->updateShipping($this->adv, $order, 3, [['product_id' => $this->orange->id, 'shipped_qty' => 38, 'reason_code' => 'CASSE']], null, false);
    $this->travel(5)->minutes();
    ($this->move)(OrderStatus::Prepared, [$order]);
    expect($order->lines()->get()->map->only(['product_id', 'validated_qty', 'shipped_qty', 'shipping_gap_reason_code'])->all())->toBe([
        ['product_id' => $this->orange->id, 'validated_qty' => 40, 'shipped_qty' => 38, 'shipping_gap_reason_code' => 'CASSE'],
        ['product_id' => $this->pomme->id, 'validated_qty' => 0, 'shipped_qty' => 0, 'shipping_gap_reason_code' => null],
    ]);

    $this->travel(5)->minutes();
    $shipped = ($this->move)(OrderStatus::Shipped, [$order]);
    $order->refresh();
    expect($shipped->applied[0]['version'])->toBe(6)
        ->and($order->status)->toBe(OrderStatus::Shipped)
        ->and($order->prep_started_at->lessThan($order->prepared_at))->toBeTrue()
        ->and($order->prepared_at->lessThan($order->shipped_at))->toBeTrue()
        ->and($order->history()->pluck('to_status')->map->value->all())->toBe(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED'])
        // Le quota n'est jamais touché par l'expédition (Q-16).
        ->and(($this->counter)($this->orange))->toBe(40);
});

it('judges the ADV perimeter at the day of each order and keeps processing after the closing (RG-S05, §4.4)', function () {
    $centre = ($this->validated)($this->a, [[$this->orange, 10]]);
    $est = ($this->validated)($this->e, [[$this->orange, 10]]);
    app(DayCalendar::class)->close($this->regionDay->businessDay);

    // ADV affecté à l'Est, et au Centre seulement à partir du lendemain.
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $restricted->id, 'kind' => AssignmentKind::Adv, 'starts_on' => '2026-10-01']);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $restricted->id, 'kind' => AssignmentKind::Adv, 'starts_on' => '2026-10-09']);

    $result = $this->orders->transition($restricted, OrderStatus::InPreparation, [
        ['id' => $centre->public_id, 'version' => 2],
        ['id' => $est->public_id, 'version' => 2],
    ]);

    expect($result->applied)->toHaveCount(1)
        ->and($result->applied[0]['id'])->toBe($est->public_id)
        ->and($result->refused[0])->toMatchArray(['id' => $centre->public_id, 'code' => 'NOT_FOUND'])
        // Un ADV jamais affecté voit tout, journée clôturée comprise.
        ->and(($this->move)(OrderStatus::InPreparation, [$centre])->applied)->toHaveCount(1);
});

it('records the shipped quantities, the gap reasons and the delivery note, with a new version and no history (RG-A03, I-79)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40], [$this->pomme, 5]]);
    expect(($this->problem)(fn () => $this->orders->updateShipping($this->adv, $order, 2, [], 'BL-1', true))->errorCode->value)->toBe('INVALID_TRANSITION');
    ($this->move)(OrderStatus::InPreparation, [$order]);

    expect(($this->invalid)(fn () => $this->orders->updateShipping($this->adv, $order, 3, [
        ['product_id' => $this->orange->id, 'shipped_qty' => 41],
        ['product_id' => $this->pomme->id, 'shipped_qty' => 4],
        ['product_id' => $this->pomme->id, 'shipped_qty' => 5],
        ['product_id' => 999_999, 'shipped_qty' => 1],
    ], null, false)))->toBe([
        'lines.0.shipped_qty' => ['La quantité expédiée doit être un nombre entier de 0 à 40 (quantité validée).'],
        'lines.1.reason_code' => ['Motif d\'écart obligatoire quand la quantité expédiée est inférieure à la quantité validée.'],
        'lines.2.product_id' => ['Ce produit figure deux fois.'],
        'lines.3.product_id' => ['Ce produit ne figure pas dans la commande.'],
    ])
        ->and(($this->invalid)(fn () => $this->orders->updateShipping($this->adv, $order, 3, [
            ['product_id' => $this->orange->id, 'shipped_qty' => 40, 'reason_code' => 'RUPTURE'],
            ['product_id' => $this->pomme->id, 'shipped_qty' => 4, 'reason_code' => 'QUOTA_INSUFFISANT'],
        ], str_repeat('B', 51), true)))->toBe([
            'lines.0.reason_code' => ['Pas de motif d\'écart quand toute la quantité validée est expédiée.'],
            'lines.1.reason_code' => ['Motif inconnu ou désactivé : choisissez un motif de la liste.'],
        ])
        ->and(($this->invalid)(fn () => $this->orders->updateShipping($this->adv, $order, 3, null, str_repeat('B', 51), true)))
        ->toBe(['delivery_note_ref' => ['Le bon de livraison ne peut pas dépasser 50 caractères.']])
        ->and(($this->problem)(fn () => $this->orders->updateShipping($this->adv, $order, 2, null, 'BL-1', true))->errorCode->value)->toBe('VERSION_CONFLICT');

    $updated = $this->orders->updateShipping($this->adv, $order, 3, [['product_id' => $this->pomme->id, 'shipped_qty' => 4, 'reason_code' => 'RUPTURE']], ' BL-0042 ', true);

    expect($updated->version)->toBe(4)
        ->and($updated->status)->toBe(OrderStatus::InPreparation)
        ->and($updated->delivery_note_ref)->toBe('BL-0042')
        ->and($updated->lines()->pluck('shipped_qty', 'product_id')->all())->toEqual([$this->orange->id => null, $this->pomme->id => 4])
        ->and(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(3)
        ->and(AuditLog::query()->where('event', 'shipping_updated')->sole()->properties->all())->toEqual([
            'number' => $order->number,
            'version' => 4,
            'lines' => ['before' => [['sku' => 'P0057', 'shipped' => null, 'reason' => null]], 'after' => [['sku' => 'P0057', 'shipped' => 4, 'reason' => 'RUPTURE']]],
            'delivery_note_ref' => ['before' => null, 'after' => 'BL-0042'],
        ]);
    Event::assertDispatched(OrderShippingUpdated::class, fn (OrderShippingUpdated $e) => $e->orderId === $order->id && $e->version === 4 && $e->actorId === $this->adv->id);

    // Rien de changé : même version, pas d'événement.
    expect($this->orders->updateShipping($this->adv, $order, 4, [['product_id' => $this->pomme->id, 'shipped_qty' => 4, 'reason_code' => 'RUPTURE']], 'BL-0042', true)->version)->toBe(4);
    Event::assertDispatchedTimes(OrderShippingUpdated::class, 1);

    // Expédiée : quantités figées, seul le bon de livraison change ; null l'efface.
    ($this->move)(OrderStatus::Prepared, [$order]);
    ($this->move)(OrderStatus::Shipped, [$order]);
    expect(($this->problem)(fn () => $this->orders->updateShipping($this->adv, $order, 6, [['product_id' => $this->orange->id, 'shipped_qty' => 39, 'reason_code' => 'CASSE']], null, false))->getMessage())
        ->toBe('Commande expédiée : les quantités ne changent plus, seul le bon de livraison peut être modifié.');
    $cleared = $this->orders->updateShipping($this->adv, $order, 6, null, '  ', true);
    expect($cleared->delivery_note_ref)->toBeNull()->and($cleared->version)->toBe(7);
});

it('cancels a validated or in-preparation order, giving V back even after the closing (RG-V07, T08, H-06, I-75, I-76)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40], [$this->pomme, 5]], [$this->pomme->id => 0]);
    $kept = ($this->validated)($this->b, [[$this->orange, 30]]);
    $decidedAt = $order->decided_at;
    expect(($this->counter)($this->orange))->toBe(70);

    expect(($this->invalid)(fn () => $this->orders->cancel($this->adv, $order, 2, 'DOUBLON', null)))->toHaveKey('reason_code')
        ->and(($this->invalid)(fn () => $this->orders->cancel($this->adv, $order, 2, 'AUTRE', null)))->toBe(['comment' => ['Commentaire obligatoire pour ce motif.']])
        ->and(($this->problem)(fn () => $this->orders->cancel($this->adv, $order, 1, 'RUPTURE', null))->errorCode->value)->toBe('VERSION_CONFLICT');

    app(DayCalendar::class)->close($this->regionDay->businessDay);
    $cancelled = $this->orders->cancel($this->adv, $order, 2, 'RUPTURE', ' Plus de stock ');

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->version)->toBe(3)
        ->and($cancelled->decision_reason_code)->toBe('RUPTURE')
        ->and($cancelled->decision_comment)->toBe('Plus de stock')
        // La validation reste ; quantités validées et totaux gardés (I-74, I-75).
        ->and($cancelled->decided_at->equalTo($decidedAt))->toBeTrue()
        ->and($cancelled->decided_by)->not->toBeNull()
        ->and($cancelled->total_ht)->toBe('36000.00')
        ->and($cancelled->lines()->pluck('validated_qty', 'product_id')->all())->toEqual([$this->orange->id => 40, $this->pomme->id => 0])
        // V(r,p) et V(c,p) restitués ; l'autre commande garde les siens.
        ->and(($this->counter)($this->orange))->toBe(30)
        ->and(app(OrderUsage::class)->forClient($order->business_day_id, $this->a->id))->toBe([])
        ->and($cancelled->history()->get()->last()->only(['from_status', 'to_status', 'actor', 'user_id', 'comment']))->toBe([
            'from_status' => OrderStatus::Validated, 'to_status' => OrderStatus::Cancelled, 'actor' => OrderActor::Adv,
            'user_id' => $this->adv->id, 'comment' => 'Plus de stock',
        ])
        ->and(AuditLog::query()->where('event', 'cancelled_by_adv')->sole()->properties->all())->toEqual([
            'number' => $order->number, 'version' => 3, 'reason' => 'RUPTURE', 'comment' => 'Plus de stock', 'released' => [['sku' => 'P0042', 'qty' => 40]],
        ]);
    Event::assertDispatched(OrderCancelledByAdv::class, fn (OrderCancelledByAdv $e) => $e->orderId === $order->id && $e->clientId === $this->a->id && $e->version === 3);
    Event::assertNotDispatched(OrderCancelled::class);

    // Renvoi : état actuel, sans effet ; Préparée ou au-delà : refusée.
    expect($this->orders->cancel($this->adv, $order, 2, 'RUPTURE', null)->version)->toBe(3)
        ->and(($this->counter)($this->orange))->toBe(30);
    ($this->move)(OrderStatus::InPreparation, [$kept]);
    ($this->move)(OrderStatus::Prepared, [$kept]);
    expect(($this->problem)(fn () => $this->orders->cancel($this->adv, $kept, 4, 'RUPTURE', null))->getMessage())
        ->toBe('Commande « Préparée » : impossible de passer à « Annulée ».');
});

it('cancels from IN_PREPARATION and frees the slot of the day for the client before the ordering cutoff (I-76, I-94)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40]]);
    ($this->move)(OrderStatus::InPreparation, [$order]);

    expect($this->orders->cancel($this->adv, $order, 3, 'DEMANDE_CLIENT', null)->status)->toBe(OrderStatus::Cancelled)
        ->and(($this->counter)($this->orange))->toBe(0)
        ->and(($this->submit)($this->a, [[$this->orange, 40]])->status)->toBe(OrderStatus::Submitted);
});

it('fails loudly, without any change, when the counter is below the quantity to give back (§5.8)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40]]);
    RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $this->orange->id)->update(['validated_qty' => 39]);

    expect(fn () => $this->orders->cancel($this->adv, $order, 2, 'RUPTURE', null))->toThrow(LogicException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Validated)
        ->and(($this->counter)($this->orange))->toBe(39);
    Event::assertNotDispatched(OrderCancelledByAdv::class);
});

it('refuses in the database itself a shipped quantity above the validated one (RG-A03, filet MySQL)', function () {
    $order = ($this->validated)($this->a, [[$this->orange, 40]]);

    expect(fn () => OrderLine::query()->where('order_id', $order->id)->update(['shipped_qty' => 41]))->toThrow(QueryException::class);
    OrderLine::query()->where('order_id', $order->id)->update(['shipped_qty' => 40]);
    expect($order->lines()->value('shipped_qty'))->toBe(40);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'Contrainte CHECK : MySQL seulement.');
