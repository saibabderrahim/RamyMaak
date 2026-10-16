<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\ClientUsage;
use App\Domain\Orders\ClientDayStatus;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderingWindow;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderUsage;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Settings\Settings;
use App\Events\OrderCancelled;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Http\Problems\ApiException;
use App\Http\Resources\Orders\OrderPresenter;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// Commandes du client (§5.4, §5.8, §6.3) : envoi, idempotence, contrôles,
// modification, annulation, expiration à la clôture, consommation S et V.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'vat_rate' => '19.00']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'vat_rate' => '19.00']);
    $this->lben = Product::factory()->create(['sku' => 'P0103', 'name' => 'Lben 1 L', 'vat_rate' => '9.00']);
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '900.00', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->pomme->id, 'price_ht' => '850.50', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->lben->id, 'price_ht' => '120.00', 'valid_from' => '2026-01-01']);

    // Orange : borne 50 pour A ; pomme et lben sans borne (politique LIBRE).
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300], [$this->lben, 800]], [
        [$this->a, $this->orange, 50],
    ]);
    $this->orders = app(ClientOrders::class);
    $this->day = BusinessClock::parseDay('2026-10-08');
    $this->submit = fn (User $by, array $lines, ?string $requestId = null, ?string $date = null, ?string $comment = null) => $this->orders->submit(
        $by,
        $requestId ?? (string) Str::uuid(),
        BusinessClock::parseDay($date ?? '2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        $comment,
    );
    $this->lines = fn (array $lines) => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines);
    $this->problem = function (Closure $call): ApiException {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }
        throw new RuntimeException('ApiException attendue');
    };
});

it('submits an order: number of the day, prices frozen, exact totals, history, audit and event (RG-C01 à RG-C03, RG-P03, RG-P04)', function () {
    ['order' => $order, 'created' => $created] = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]], comment: '  Livraison avant 10 h  ');

    expect($created)->toBeTrue()
        ->and($order->number)->toBe('RMK-261008-00001')
        ->and($order->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($order->status)->toBe(OrderStatus::Submitted)
        ->and($order->version)->toBe(1)
        ->and($order->region_id)->toBe($this->centre->id)
        ->and($order->submitted_by)->toBe($this->accountA->id)
        ->and($order->client_comment)->toBe('Livraison avant 10 h')
        // HT : 36 000 + 2 551,50 + 840 ; TVA par ligne : 6 840 + 484,79 (484,785 → 484,79) + 75,60.
        ->and($order->total_ht)->toBe('39391.50')
        ->and($order->total_vat)->toBe('7400.39')
        ->and($order->total_ttc)->toBe('46791.89')
        ->and($order->lines()->pluck('unit_price_ht', 'product_id')->all())->toEqual([
            $this->orange->id => '900.00', $this->pomme->id => '850.50', $this->lben->id => '120.00',
        ])
        ->and($order->history()->first())->from_status->toBeNull()->to_status->toBe(OrderStatus::Submitted)->user_id->toBe($this->accountA->id)
        ->and(AuditLog::query()->where('log_name', 'orders')->where('event', 'submitted')->count())->toBe(1);
    Event::assertDispatched(OrderSubmitted::class, fn (OrderSubmitted $e) => $e->orderId === $order->id && $e->regionId === $this->centre->id && $e->version === 1);

    // Numéro suivant pour un autre client ; un changement de prix ultérieur ne touche pas la commande (RG-P03).
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '990.00', 'valid_from' => '2026-10-08']);
    expect(($this->submit)($this->accountB, [[$this->orange, 1]])['order']->number)->toBe('RMK-261008-00002')
        ->and($order->lines()->where('product_id', $this->orange->id)->value('unit_price_ht'))->toBe('900.00');
});

it('returns the existing order when the same request is sent again, even later or from another account (RG-C07)', function () {
    $id = (string) Str::uuid();
    $first = ($this->submit)($this->accountA, [[$this->orange, 10]], $id);
    $again = ($this->submit)($this->accountA, [[$this->orange, 99]], strtoupper($id));
    $this->travelTo(CarbonImmutable::parse('2026-10-08 14:00', 'Africa/Algiers'));
    $late = ($this->submit)($this->secondA, [[$this->orange, 10]], $id);

    expect($again['created'])->toBeFalse()->and($again['order']->id)->toBe($first['order']->id)
        ->and($late['created'])->toBeFalse()->and($late['order']->id)->toBe($first['order']->id)
        ->and(Order::query()->count())->toBe(1);
    Event::assertDispatchedTimes(OrderSubmitted::class, 1);
});

it('refuses a blocked client, a day not opened or not distributed, a closed day and a passed cutoff (RG-C01)', function () {
    $this->b->forceFill(['status' => 'BLOCKED'])->save();
    expect(($this->problem)(fn () => ($this->submit)($this->accountB, [[$this->pomme, 1]]))->errorCode->value)->toBe('CLIENT_BLOCKED');

    // Demain : journée non ouverte, puis ouverte sans répartition envoyée.
    expect(($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->pomme, 1]], date: '2026-10-11'))->errorCode->value)->toBe('DAY_NOT_OPEN');
    app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-11'), userWithRole(Role::Adv));
    expect(($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->pomme, 1]], date: '2026-10-11'))->errorCode->value)->toBe('DAY_NOT_OPEN');

    // 13:00 passée : refusé ; une prolongation de l'ADV pour la région rouvre la commande (RG-A05).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'Africa/Algiers'));
    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->pomme, 1]]));
    expect($e->errorCode->value)->toBe('CUTOFF_PASSED')->and($e->getMessage())->toBe('L\'heure limite de commande (13:00) est dépassée.');
    app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Ordering, '13:30', userWithRole(Role::Adv));
    expect(($this->submit)($this->accountA, [[$this->pomme, 1]])['created'])->toBeTrue();

    // Clôture : DAY_NOT_OPEN.
    app(DayCalendar::class)->close($this->regionDay->businessDay);
    expect(($this->problem)(fn () => ($this->submit)($this->accountB, [[$this->pomme, 1]]))->errorCode->value)->toBe('CLIENT_BLOCKED');
    expect(($this->problem)(fn () => ($this->submit)($this->secondA, [[$this->pomme, 1]]))->errorCode->value)->toBe('DAY_NOT_OPEN');
});

it('counts the orders of the day without the rejected, cancelled and expired ones, and checks the cumulated limit (RG-C04)', function () {
    $first = ($this->submit)($this->accountA, [[$this->orange, 30]])['order'];
    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->pomme, 1]]));
    expect($e->errorCode->value)->toBe('MAX_ORDERS_REACHED')->and($e->extra['max_orders_per_day'])->toBe(1);

    $this->orders->cancel($this->accountA, $first->public_id, 1);
    Order::factory()->rejected()->create(['client_id' => $this->a->id, 'business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id]);
    expect(($this->submit)($this->accountA, [[$this->orange, 50]])['created'])->toBeTrue();

    // Deux commandes permises : la seconde est limitée par B − E (50 − 50 = 0).
    app(Settings::class)->update(['orders' => ['max_per_day' => 2]], null);
    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->orange, 1]]));
    expect($e->errorCode->value)->toBe('CLIENT_LIMIT_EXCEEDED')->and($e->extra['violations'])->toBe([
        ['line' => 0, 'product_id' => $this->orange->id, 'requested' => 1, 'max_allowed' => 0],
    ]);
});

it('lists every unavailable product, then every quantity above the available one (RG-C02, RG-C03, RG-B01, RG-B04, RG-C09)', function () {
    $noQuota = Product::factory()->create();
    $noPrice = Product::factory()->create();
    $forbidden = Product::factory()->create();
    ProductPrice::factory()->create(['product_id' => $forbidden->id, 'valid_from' => '2026-01-01']);
    $regionDay = openOrderingDay('2026-10-08', $this->centre, [[$noPrice, 100], [$forbidden, 100]], [[$this->a, $forbidden, 0]]);
    $noPrice->prices()->delete();

    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->orange, 1], [$noQuota, 1], [$noPrice, 1], [$forbidden, 1]]));
    expect($e->errorCode->value)->toBe('PRODUCT_NOT_AVAILABLE')->and($e->status())->toBe(422)
        ->and(array_column($e->extra['violations'], 'line'))->toBe([1, 2, 3]);

    // Au-dessus de la borne : maximum indiqué ; sans borne, au-dessus du reste région : jamais révélé.
    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->orange, 60]]));
    expect($e->errorCode->value)->toBe('CLIENT_LIMIT_EXCEEDED')->and($e->getMessage())->toBe('« Jus orange 1 L » : maximum 50.');
    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->orange, 51], [$this->pomme, 301]]));
    expect($e->extra['violations'])->toBe([
        ['line' => 0, 'product_id' => $this->orange->id, 'requested' => 51, 'max_allowed' => 50],
        ['line' => 1, 'product_id' => $this->pomme->id, 'requested' => 301, 'max_allowed' => null],
    ])->and($e->getMessage())->toBe('2 lignes dépassent la quantité disponible.');

    // Politique OBLIGATOIRE : un produit sans borne n'est pas commandable.
    app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);
    expect(($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->pomme, 1]]))->errorCode->value)->toBe('PRODUCT_NOT_AVAILABLE')
        ->and(($this->submit)($this->accountA, [[$this->orange, 50]])['created'])->toBeTrue();
});

it('refuses an order below the minimum amount when it is set (RG-C06)', function () {
    app(Settings::class)->update(['orders' => ['min_amount' => 1000]], null);

    $e = ($this->problem)(fn () => ($this->submit)($this->accountA, [[$this->lben, 8]]));
    expect($e->errorCode->value)->toBe('ORDER_BELOW_MINIMUM')->and($e->status())->toBe(422)
        ->and($e->extra)->toBe(['min_amount_ht' => 1000.0, 'total_ht' => 960.0])
        ->and($e->getMessage())->toBe('Montant minimum de commande : 1 000,00 DA HT (commande : 960,00 DA HT).')
        ->and(($this->submit)($this->accountA, [[$this->lben, 9]])['created'])->toBeTrue();
});

it('modifies a submitted order: new version, lines replaced, frozen prices kept, own quantities excluded from E (RG-C05, I-47)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 50], [$this->pomme, 2]])['order'];
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '990.00', 'valid_from' => '2026-10-08']);
    ProductPrice::factory()->create(['product_id' => $this->lben->id, 'price_ht' => '130.00', 'valid_from' => '2026-10-08']);

    // Garder 50 d'orange est permis : E(c,p) exclut la commande modifiée.
    $modified = $this->orders->modify($this->secondA, strtolower($order->public_id), 1, ($this->lines)([[$this->orange, 45], [$this->lben, 10]]), 'Merci');

    expect($modified->version)->toBe(2)
        ->and($modified->number)->toBe($order->number)
        ->and($modified->submitted_by)->toBe($this->accountA->id)
        ->and($modified->client_comment)->toBe('Merci')
        ->and($modified->lines()->get()->mapWithKeys(fn ($line) => [$line->product_id => [$line->requested_qty, $line->unit_price_ht]])->all())->toBe([
            $this->orange->id => [45, '900.00'],
            $this->lben->id => [10, '130.00'],
        ])
        ->and($modified->total_ht)->toBe('41800.00')
        ->and($modified->history()->pluck('version')->all())->toBe([1, 2]);
    Event::assertDispatched(OrderUpdated::class, fn (OrderUpdated $e) => $e->version === 2 && $e->actorId === $this->secondA->id);

    // Rien de changé : même version, pas d'événement.
    $same = $this->orders->modify($this->accountA, $order->public_id, 2, ($this->lines)([[$this->lben, 10], [$this->orange, 45]]), 'Merci');
    expect($same->version)->toBe(2);
    Event::assertDispatchedTimes(OrderUpdated::class, 1);

    // Version périmée, borne dépassée, commande d'un autre client.
    expect(($this->problem)(fn () => $this->orders->modify($this->accountA, $order->public_id, 1, ($this->lines)([[$this->orange, 1]]), null))->errorCode->value)->toBe('VERSION_CONFLICT')
        ->and(($this->problem)(fn () => $this->orders->modify($this->accountA, $order->public_id, 2, ($this->lines)([[$this->orange, 51]]), null))->errorCode->value)->toBe('CLIENT_LIMIT_EXCEEDED')
        ->and(($this->problem)(fn () => $this->orders->modify($this->accountB, $order->public_id, 2, ($this->lines)([[$this->orange, 1]]), null))->errorCode->value)->toBe('NOT_FOUND')
        ->and(($this->problem)(fn () => $this->orders->modify($this->accountA, 'not-an-ulid', 2, ($this->lines)([[$this->orange, 1]]), null))->errorCode->value)->toBe('NOT_FOUND');
});

it('cancels a submitted order before the cutoff, even for a blocked client, and nothing afterwards (§6.3, I-53)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 10]])['order'];
    $this->a->forceFill(['status' => 'BLOCKED'])->save();

    expect(($this->problem)(fn () => $this->orders->modify($this->accountA, $order->public_id, 1, ($this->lines)([[$this->orange, 5]]), null))->errorCode->value)->toBe('CLIENT_BLOCKED');
    $cancelled = $this->orders->cancel($this->accountA, $order->public_id, 1);
    expect($cancelled->status)->toBe(OrderStatus::Cancelled)->and($cancelled->version)->toBe(2)
        ->and($cancelled->history()->get()->last()->to_status)->toBe(OrderStatus::Cancelled);
    Event::assertDispatched(OrderCancelled::class);

    expect(($this->problem)(fn () => $this->orders->cancel($this->accountA, $order->public_id, 2))->errorCode->value)->toBe('INVALID_TRANSITION');

    $this->a->forceFill(['status' => 'ACTIVE'])->save();
    $other = ($this->submit)($this->accountA, [[$this->orange, 10]])['order'];
    $this->travelTo(CarbonImmutable::parse('2026-10-08 13:01', 'Africa/Algiers'));
    expect(($this->problem)(fn () => $this->orders->cancel($this->accountA, $other->public_id, 1))->errorCode->value)->toBe('CUTOFF_PASSED');
});

it('expires the submitted orders at the closing, once, and frees their pending quantities (§4.4)', function () {
    $submitted = ($this->submit)($this->accountA, [[$this->orange, 10]])['order'];
    $cancelled = ($this->submit)($this->accountB, [[$this->pomme, 5]])['order'];
    $this->orders->cancel($this->accountB, $cancelled->public_id, 1);
    expect(app(OrderUsage::class)->forRegionDay($this->regionDay))->toBe([$this->a->id => [$this->orange->id => ['pending' => 10, 'validated' => 0]]]);

    $calendar = app(DayCalendar::class);
    expect($calendar->close($this->regionDay->businessDay))->toBeTrue()
        ->and($calendar->close($this->regionDay->businessDay->fresh()))->toBeFalse();

    expect($submitted->fresh())->status->toBe(OrderStatus::Expired)->version->toBe(2)
        ->and($submitted->history()->get()->last())->to_status->toBe(OrderStatus::Expired)->user_id->toBeNull()
        ->and($cancelled->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(app(OrderUsage::class)->forRegionDay($this->regionDay))->toBe([])
        ->and(AuditLog::query()->where('log_name', 'orders')->where('event', 'expired')->first()->properties['numbers'])->toBe([$submitted->number]);
    Event::assertDispatchedTimes(OrdersExpired::class, 1);
    Event::assertDispatched(OrdersExpired::class, fn (OrdersExpired $e) => $e->orderIds === [$submitted->id]);
});

it('reads S and V per client and product for the grid, region and day of the order only (§5.1)', function () {
    expect(app(ClientUsage::class))->toBeInstanceOf(OrderUsage::class);
    ($this->submit)($this->accountA, [[$this->orange, 10], [$this->pomme, 3]]);
    ($this->submit)($this->accountB, [[$this->pomme, 4]]);
    // Commande validée (lot 6) : V(c,p) = quantité validée ; commande d'une autre région ignorée.
    $validated = Order::factory()->validated()->create(['client_id' => $this->b->id, 'business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id]);
    $validated->lines()->create(['product_id' => $this->lben->id, 'requested_qty' => 9, 'validated_qty' => 6, 'unit_price_ht' => '120.00', 'vat_rate' => '9.00']);
    $elsewhere = Order::factory()->create(['client_id' => $this->b->id, 'business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->est->id]);
    $elsewhere->lines()->create(['product_id' => $this->lben->id, 'requested_qty' => 99, 'unit_price_ht' => '120.00', 'vat_rate' => '9.00']);

    expect(app(OrderUsage::class)->forRegionDay($this->regionDay))->toEqual([
        $this->a->id => [$this->orange->id => ['pending' => 10, 'validated' => 0], $this->pomme->id => ['pending' => 3, 'validated' => 0]],
        $this->b->id => [$this->pomme->id => ['pending' => 4, 'validated' => 0], $this->lben->id => ['pending' => 0, 'validated' => 6]],
    ]);
});

it('derives the ordering status of the day and proposes the next open day (EF-CLT-01, I-42, I-43)', function () {
    $window = app(OrderingWindow::class);
    $state = $window->state($this->a, $this->day);
    expect($state->status)->toBe(ClientDayStatus::OrderingOpen)
        ->and(BusinessClock::toLocal($state->cutoffAt)->format('H:i'))->toBe('13:00')
        ->and($state->extended)->toBeFalse()
        ->and($window->state($this->a, BusinessClock::parseDay('2026-10-11'))->status)->toBe(ClientDayStatus::WaitingQuotas);

    // Après 13:00 : commandes clôturées ; demain (dimanche 11/10) répartition envoyée la veille → journée proposée.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Africa/Algiers'));
    expect($window->state($this->a, $this->day)->status)->toBe(ClientDayStatus::OrderingClosed)
        ->and($window->defaultDay($this->a)->format('Y-m-d'))->toBe('2026-10-08');
    openOrderingDay('2026-10-11', $this->centre, [[$this->orange, 100]]);
    expect($window->defaultDay($this->a)->format('Y-m-d'))->toBe('2026-10-11');

    app(DayCalendar::class)->close($this->regionDay->businessDay);
    expect($window->state($this->a, $this->day)->status)->toBe(ClientDayStatus::DayClosed);
});

it('allows only the transitions of §6.3, with their actor', function () {
    $workflow = app(OrderWorkflow::class);
    $allowed = [];
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            foreach (OrderActor::cases() as $actor) {
                if ($workflow->can($from, $to, $actor)) {
                    $allowed[] = "{$from->value}>{$to->value}:{$actor->value}";
                }
            }
        }
    }

    expect($allowed)->toBe([
        'SUBMITTED>SUBMITTED:CLIENT', 'SUBMITTED>VALIDATED:REGIONAL', 'SUBMITTED>REJECTED:REGIONAL',
        'SUBMITTED>CANCELLED:CLIENT', 'SUBMITTED>EXPIRED:SYSTEM',
        'VALIDATED>CANCELLED:ADV', 'VALIDATED>IN_PREPARATION:ADV',
        'IN_PREPARATION>CANCELLED:ADV', 'IN_PREPARATION>PREPARED:ADV',
        'PREPARED>SHIPPED:ADV', 'SHIPPED>DELIVERED:ADV',
    ]);
    expect(fn () => $workflow->assertCan(OrderStatus::Expired, OrderStatus::Submitted, OrderActor::Client))
        ->toThrow(ApiException::class, 'Commande « Expirée » : impossible de passer à « Envoyée ».');
});

it('presents an order for the client and the regional views (contrat §3, §4)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->lben, 7]])['order'];
    $order = $this->orders->modify($this->secondA, $order->public_id, 1, ($this->lines)([[$this->orange, 40], [$this->lben, 8]]), null);
    $order->load(OrderPresenter::RELATIONS);

    expect(OrderPresenter::summary($order))->toBe([
        'id' => $order->public_id,
        'number' => 'RMK-261008-00001',
        'day' => '2026-10-08',
        'status' => 'SUBMITTED',
        'status_label' => 'Envoyée',
        'version' => 2,
        'submitted_at' => '2026-10-08T08:30:00+00:00',
        'total_ht' => 36960.0,
        'total_ttc' => 43886.4,
        'lines_count' => 2,
        'is_adjusted' => false,
    ])
        ->and(OrderPresenter::lines($order)[1])->toMatchArray([
            'product_id' => $this->lben->id, 'sku' => 'P0103', 'photo' => null, 'requested_qty' => 8,
            'validated_qty' => null, 'unit_price_ht' => 120.0, 'vat_rate' => 9.0, 'amount_ht' => 960.0,
        ])
        ->and(OrderPresenter::history($order, false))->toBe([
            ['from' => null, 'to' => 'SUBMITTED', 'version' => 1, 'at' => '2026-10-08T08:30:00+00:00', 'actor' => 'CLIENT', 'by_name' => $this->accountA->name, 'comment' => null],
            ['from' => 'SUBMITTED', 'to' => 'SUBMITTED', 'version' => 2, 'at' => '2026-10-08T08:30:00+00:00', 'actor' => 'CLIENT', 'by_name' => $this->secondA->name, 'comment' => null],
        ]);
});
