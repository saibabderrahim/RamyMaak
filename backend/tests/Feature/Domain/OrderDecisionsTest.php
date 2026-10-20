<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderUsage;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\OrderCancelled;
use App\Events\OrderRejected;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\OrderValidated;
use App\Http\Problems\ApiException;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Décision du régional (§5.5, §5.8, §6.3 ; contrat du lot 6, §3) :
// validation avec ou sans ajustement, plafonds région et client, ordre des
// contrôles, renvoi, rejet ; quantités V réelles (QuotaLedger).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00, validation jusqu'à 15:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class, OrderValidated::class, OrderRejected::class, CutoffExtended::class]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
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
    $this->decisions = app(OrderDecisions::class);
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
    $this->validate = fn (Order $order, array $lines, ?User $by = null, ?int $version = null): Order => $this->decisions->validate(
        $by ?? $this->titular,
        $order,
        $version ?? $order->fresh()->version,
        ($this->lines)($lines),
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
    $this->settings = fn (array $values) => app(Settings::class)->update($values, null);
});

it('validates an order in full: counters, history, audit, event after the commit (§5.8, RG-V02)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]]);

    $validated = ($this->validate)($order, [[$this->lben, 7], [$this->orange, 40], [$this->pomme, 3]]);

    expect($validated->status)->toBe(OrderStatus::Validated)
        ->and($validated->version)->toBe(2)
        ->and($validated->decided_by)->toBe($this->titular->id)
        ->and($validated->decided_at->equalTo(now()))->toBeTrue()
        ->and($validated->is_adjusted)->toBeFalse()
        // Totaux inchangés : toutes les quantités validées.
        ->and($validated->total_ht)->toBe('39391.50')
        ->and($validated->total_vat)->toBe('7400.39')
        ->and($validated->total_ttc)->toBe('46791.89')
        ->and($validated->lines()->pluck('validated_qty', 'product_id')->all())->toEqual([$this->orange->id => 40, $this->pomme->id => 3, $this->lben->id => 7])
        ->and(($this->counter)($this->orange))->toBe(40)
        ->and(($this->counter)($this->pomme))->toBe(3)
        ->and(($this->counter)($this->lben))->toBe(7)
        // S devient V (§6.3).
        ->and(app(OrderUsage::class)->forClient($this->regionDay->business_day_id, $this->a->id)[$this->orange->id])->toBe(['pending' => 0, 'validated' => 40]);

    $history = $validated->history()->get()->last();
    expect($history->only(['from_status', 'to_status', 'version', 'user_id', 'actor', 'comment']))->toBe([
        'from_status' => OrderStatus::Submitted, 'to_status' => OrderStatus::Validated, 'version' => 2,
        'user_id' => $this->titular->id, 'actor' => OrderActor::Regional, 'comment' => null,
    ]);
    $audit = AuditLog::query()->where('log_name', 'orders')->where('event', 'validated')->sole();
    expect($audit->causer_id)->toBe($this->titular->id)
        ->and($audit->properties->all())->toEqual([
            'number' => 'RMK-261008-00001',
            'version' => 2,
            'lines' => [
                ['sku' => 'P0103', 'requested' => 7, 'validated' => 7, 'reason' => null, 'comment' => null],
                ['sku' => 'P0042', 'requested' => 40, 'validated' => 40, 'reason' => null, 'comment' => null],
                ['sku' => 'P0057', 'requested' => 3, 'validated' => 3, 'reason' => null, 'comment' => null],
            ],
            'adjusted_lines' => 0,
            'total_ht' => 39391.5,
        ]);
    Event::assertDispatched(OrderValidated::class, fn (OrderValidated $e) => $e->orderId === $order->id && $e->clientId === $this->a->id
        && $e->regionId === $this->centre->id && $e->businessDayId === $this->regionDay->business_day_id
        && $e->version === 2 && $e->actorId === $this->titular->id && $e->adjustedLines === 0);
});

it('validates with adjustments: reason and comment on the reduced lines, totals on the validated quantities (RG-V04, RG-P04, I-72, I-73)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 7]]);

    $validated = ($this->validate)($order, [
        [$this->orange, 30, 'QUOTA_INSUFFISANT', '  Reste région limité '],
        [$this->pomme, 0, 'AUTRE', 'Plus de pomme'],
        [$this->lben, 7],
    ], $this->deputy);

    // HT : 27 000 + 0 + 840 ; TVA : 5 130 + 75,60.
    expect($validated->is_adjusted)->toBeTrue()
        ->and($validated->decided_by)->toBe($this->deputy->id)
        ->and($validated->total_ht)->toBe('27840.00')
        ->and($validated->total_vat)->toBe('5205.60')
        ->and($validated->total_ttc)->toBe('33045.60')
        ->and($validated->lines()->get()->map->only(['product_id', 'requested_qty', 'validated_qty', 'adjustment_reason_code', 'adjustment_comment'])->all())->toBe([
            ['product_id' => $this->orange->id, 'requested_qty' => 40, 'validated_qty' => 30, 'adjustment_reason_code' => 'QUOTA_INSUFFISANT', 'adjustment_comment' => 'Reste région limité'],
            ['product_id' => $this->pomme->id, 'requested_qty' => 3, 'validated_qty' => 0, 'adjustment_reason_code' => 'AUTRE', 'adjustment_comment' => 'Plus de pomme'],
            ['product_id' => $this->lben->id, 'requested_qty' => 7, 'validated_qty' => 7, 'adjustment_reason_code' => null, 'adjustment_comment' => null],
        ])
        // Une ligne validée à 0 ne touche pas le compteur (§5.8).
        ->and(($this->counter)($this->pomme))->toBe(0)
        ->and(($this->counter)($this->orange))->toBe(30)
        ->and($validated->history()->get()->last()->user_id)->toBe($this->deputy->id)
        ->and(AuditLog::query()->where('event', 'validated')->sole()->properties['adjusted_lines'])->toBe(2);
    Event::assertDispatched(OrderValidated::class, fn (OrderValidated $e) => $e->adjustedLines === 2 && $e->actorId === $this->deputy->id);
});

it('refuses the whole validation with every line in excess, its cause and the maximum validatable (RG-V02, RG-V03, I-69)', function () {
    ($this->settings)(['orders' => ['max_per_day' => 2]]);
    $first = ($this->submit)($this->accountA, [[$this->orange, 30]]);
    $second = ($this->submit)($this->accountA, [[$this->orange, 20], [$this->pomme, 10]]);
    $other = ($this->submit)($this->accountB, [[$this->pomme, 295]]);
    validateOrder($first);
    validateOrder($other);
    // Le régional baisse ensuite la borne de A sur l'orange à 40 (≥ V(c,p) = 30).
    $limits = app(LimitBook::class);
    $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 40]], $this->titular);
    $limits->publish($this->regionDay, $this->titular);

    $e = ($this->problem)(fn () => ($this->validate)($second, [[$this->orange, 20], [$this->pomme, 10]]));

    expect($e->errorCode->value)->toBe('REGION_QUOTA_EXCEEDED')
        ->and($e->getMessage())->toBe('La validation dépasserait le quota de la région pour 1 produit.')
        ->and($e->extra['violations'])->toBe([
            ['line' => 0, 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 20, 'quota' => 100, 'validated' => 30,
                'limit' => 40, 'client_validated' => 30, 'max_allowed' => 10, 'cause' => 'CLIENT_LIMIT'],
            ['line' => 1, 'product_id' => $this->pomme->id, 'sku' => 'P0057', 'requested' => 10, 'quota' => 300, 'validated' => 295,
                'limit' => null, 'client_validated' => 0, 'max_allowed' => 5, 'cause' => 'REGION_QUOTA'],
        ]);
    // Tout est annulé : version, lignes, compteurs.
    expect($second->fresh())->status->toBe(OrderStatus::Submitted)->version->toBe(1)
        ->and($second->lines()->whereNotNull('validated_qty')->exists())->toBeFalse()
        ->and(($this->counter)($this->orange))->toBe(30)
        ->and(($this->counter)($this->pomme))->toBe(295);
    Event::assertDispatchedTimes(OrderValidated::class, 2);

    // Seule la borne dépassée : CLIENT_LIMIT_EXCEEDED.
    $e = ($this->problem)(fn () => ($this->validate)($second, [[$this->orange, 20], [$this->pomme, 5, 'QUOTA_INSUFFISANT']]));
    expect($e->errorCode->value)->toBe('CLIENT_LIMIT_EXCEEDED')
        ->and($e->status())->toBe(409)
        ->and($e->getMessage())->toBe('La validation dépasserait la borne du client pour 1 produit.')
        ->and(array_column($e->extra['violations'], 'cause'))->toBe(['CLIENT_LIMIT']);

    // Au maximum : validé, la pomme est épuisée (V = Q).
    ($this->validate)($second, [[$this->orange, 10, 'BORNE_ATTEINTE'], [$this->pomme, 5, 'QUOTA_INSUFFISANT']]);
    expect(($this->counter)($this->orange))->toBe(40)->and(($this->counter)($this->pomme))->toBe(300);
});

it('never caps a borne 0 implicitly, and applies no client ceiling without a borne, even under REQUIRED (I-91)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 5]]);
    $limits = app(LimitBook::class);
    $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 0]], $this->titular);
    $limits->publish($this->regionDay, $this->titular);
    ($this->settings)(['limits' => ['no_limit_policy' => 'REQUIRED']]);
    // Produit désactivé après l'envoi : toujours validable.
    $this->pomme->forceFill(['is_active' => false])->save();

    $e = ($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 40], [$this->pomme, 5]]));
    expect($e->errorCode->value)->toBe('CLIENT_LIMIT_EXCEEDED')
        ->and($e->extra['violations'][0])->toMatchArray(['limit' => 0, 'max_allowed' => 0, 'cause' => 'CLIENT_LIMIT'])
        ->and($e->extra['violations'])->toHaveCount(1);

    $validated = ($this->validate)($order, [[$this->orange, 0, 'BORNE_ATTEINTE'], [$this->pomme, 5]]);
    expect($validated->status)->toBe(OrderStatus::Validated)->and(($this->counter)($this->pomme))->toBe(5);
});

it('checks the transition, the version, the day, the cutoff, the client, the lines, then the ceilings, in this order (I-68)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40]]);
    $lines = [[$this->orange, 40]];

    // Commande modifiée par le client : VERSION_CONFLICT avec la version actuelle.
    app(ClientOrders::class)->modify($this->accountA, $order->public_id, 1, [['product_id' => $this->orange->id, 'quantity' => 45]], null);
    $e = ($this->problem)(fn () => ($this->validate)($order, $lines, version: 1));
    expect($e->errorCode->value)->toBe('VERSION_CONFLICT')->and($e->extra)->toBe(['version' => 2]);
    // Borne de A sur l'orange baissée à 40 depuis l'envoi.
    $limits = app(LimitBook::class);
    $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 40]], $this->titular);
    $limits->publish($this->regionDay, $this->titular);

    // Client bloqué, heure limite bloquante dépassée, journée close : la version passe d'abord.
    $this->a->forceFill(['status' => 'BLOCKED'])->save();
    ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Africa/Algiers'));
    BusinessDay::query()->whereKey($this->regionDay->business_day_id)->update(['status' => 'CLOSED']);
    expect(($this->problem)(fn () => ($this->validate)($order, $lines, version: 1))->errorCode->value)->toBe('VERSION_CONFLICT')
        ->and(($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 45]]))->errorCode->value)->toBe('DAY_NOT_OPEN');

    BusinessDay::query()->whereKey($this->regionDay->business_day_id)->update(['status' => 'OPEN']);
    RegionDay::query()->whereKey($this->regionDay->id)->update(['status' => 'CLOSED']);
    expect(($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 45]]))->errorCode->value)->toBe('DAY_NOT_OPEN');

    RegionDay::query()->whereKey($this->regionDay->id)->update(['status' => 'DISTRIBUTION_PUBLISHED']);
    $e = ($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 45]]));
    expect($e->errorCode->value)->toBe('CUTOFF_PASSED')
        ->and($e->extra)->toBe(['cutoff' => '15:00'])
        ->and($e->getMessage())->toBe('L\'heure limite de validation (15:00) est dépassée : la commande ne peut plus être validée, seulement rejetée.');

    ($this->settings)(['cutoffs' => ['validation_blocking' => false]]);
    $e = ($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 99]]));
    expect($e->errorCode->value)->toBe('CLIENT_BLOCKED')->and($e->status())->toBe(403);

    // Lignes invalides (422) avant les plafonds (409, borne de 40).
    $this->a->forceFill(['status' => 'ACTIVE'])->save();
    expect(($this->invalid)(fn () => ($this->validate)($order, [[$this->orange, 99]])))->toHaveKey('lines.0.validated_qty');
    expect(($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 45]]))->errorCode->value)->toBe('CLIENT_LIMIT_EXCEEDED');

    // Déjà validée : INVALID_TRANSITION, même avec une version périmée.
    validateOrder($order, [$this->orange->id => 40], $this->deputy);
    $e = ($this->problem)(fn () => ($this->validate)($order, $lines, version: 1));
    expect($e->errorCode->value)->toBe('INVALID_TRANSITION')
        ->and($e->getMessage())->toBe('Commande « Validée » : impossible de passer à « Validée ».');
});

it('checks the meaning of the lines after the locks (RG-V04, RG-V05, I-72, I-73)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3]]);

    expect(($this->invalid)(fn () => ($this->validate)($order, [[$this->orange, 40]])))->toBe(['lines' => ['Les lignes doivent reprendre exactement les produits de la commande, une fois chacun.']])
        ->and(($this->invalid)(fn () => ($this->validate)($order, [[$this->orange, 40], [$this->pomme, 3], [$this->lben, 1]])))->toHaveKey('lines')
        ->and(($this->invalid)(fn () => ($this->validate)($order, [[$this->orange, 40], [$this->orange, 3]])))->toHaveKey('lines')
        ->and(($this->invalid)(fn () => ($this->validate)($order, [[$this->orange, 0, 'AUTRE', 'x'], [$this->pomme, 0, 'AUTRE', 'y']])))->toBe(['lines' => ['Toutes les lignes sont à 0 : rejetez la commande.']]);

    expect(($this->invalid)(fn () => ($this->validate)($order, [
        [$this->orange, 41],
        [$this->pomme, -1],
    ])))->toBe([
        'lines.0.validated_qty' => ['La quantité validée doit être un nombre entier de 0 à 40 (quantité demandée).'],
        'lines.1.validated_qty' => ['La quantité validée doit être un nombre entier de 0 à 3 (quantité demandée).'],
    ]);
    expect(($this->invalid)(fn () => ($this->validate)($order, [
        [$this->orange, 30],
        [$this->pomme, 3, 'QUOTA_INSUFFISANT', 'Inutile'],
    ])))->toBe([
        'lines.0.reason_code' => ['Motif obligatoire pour une ligne réduite.'],
        'lines.1.reason_code' => ['Pas de motif sur une ligne validée en entier.'],
        'lines.1.comment' => ['Pas de commentaire sur une ligne validée en entier.'],
    ]);
    // Motif d'un autre type, motif exigeant un commentaire, commentaire trop long.
    expect(($this->invalid)(fn () => ($this->validate)($order, [
        [$this->orange, 30, 'QUOTA_EPUISE'],
        [$this->pomme, 1, 'AUTRE', '   '],
    ])))->toBe([
        'lines.0.reason_code' => ['Motif inconnu ou désactivé : choisissez un motif de la liste.'],
        'lines.1.comment' => ['Commentaire obligatoire pour ce motif.'],
    ])
        ->and(($this->invalid)(fn () => ($this->validate)($order, [[$this->orange, 30, 'DEMANDE_CLIENT', str_repeat('é', 201)], [$this->pomme, 3]])))
        ->toBe(['lines.0.comment' => ['Le commentaire ne peut pas dépasser 200 caractères.']]);

    // Rien n'a été écrit ; une quantité envoyée en texte est acceptée.
    expect($order->fresh()->version)->toBe(1)
        ->and(($this->validate)($order, [[$this->orange, '40'], [$this->pomme, '3']])->status)->toBe(OrderStatus::Validated);
});

it('blocks the validation after a blocking cutoff, reopened by a regional extension; non-blocking by default (RG-V08, I-71)', function () {
    $first = ($this->submit)($this->accountA, [[$this->orange, 10]]);
    $second = ($this->submit)($this->accountB, [[$this->pomme, 10]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Africa/Algiers'));

    // Par défaut, non bloquante.
    expect(($this->validate)($first, [[$this->orange, 10]])->status)->toBe(OrderStatus::Validated);

    ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
    expect(($this->problem)(fn () => ($this->validate)($second, [[$this->pomme, 10]]))->errorCode->value)->toBe('CUTOFF_PASSED');

    app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Validation, '16:00', userWithRole(Role::Adv));
    expect(($this->validate)($second, [[$this->pomme, 10]])->status)->toBe(OrderStatus::Validated);
});

it('returns the current state without effect when the same decision is sent again (I-92)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40]]);
    ($this->validate)($order, [[$this->orange, 40]]);

    $again = ($this->validate)($order, [[$this->orange, 40]], version: 1);

    expect($again->status)->toBe(OrderStatus::Validated)->and($again->version)->toBe(2)
        ->and(($this->counter)($this->orange))->toBe(40)
        ->and(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'validated')->count())->toBe(1);
    Event::assertDispatchedTimes(OrderValidated::class, 1);
    // Un autre auteur : la commande est déjà traitée.
    expect(($this->problem)(fn () => ($this->validate)($order, [[$this->orange, 40]], $this->deputy, 1))->errorCode->value)->toBe('INVALID_TRANSITION');

    $other = ($this->submit)($this->accountB, [[$this->pomme, 3]]);
    $this->decisions->reject($this->titular, $other, 1, 'DOUBLON', null);
    expect($this->decisions->reject($this->titular, $other, 1, 'DOUBLON', null)->version)->toBe(2)
        ->and(($this->problem)(fn () => $this->decisions->reject($this->deputy, $other, 1, 'DOUBLON', null))->errorCode->value)->toBe('INVALID_TRANSITION');
    Event::assertDispatchedTimes(OrderRejected::class, 1);
});

it('rejects with a reason, freeing the slot of the day, even after a blocking cutoff or for a blocked client (RG-V05, I-70, I-74, I-94)', function () {
    $order = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 3]]);

    expect(($this->invalid)(fn () => $this->decisions->reject($this->titular, $order, 1, 'AUTRE', ' ')))->toBe(['comment' => ['Commentaire obligatoire pour ce motif.']])
        ->and(($this->invalid)(fn () => $this->decisions->reject($this->titular, $order, 1, 'QUOTA_INSUFFISANT', null)))->toHaveKey('reason_code')
        ->and(($this->problem)(fn () => $this->decisions->reject($this->titular, $order, 2, 'DOUBLON', null))->errorCode->value)->toBe('VERSION_CONFLICT');

    $rejected = $this->decisions->reject($this->deputy, $order, 1, 'AUTRE', ' Commande en double avec celle de 9 h ');

    expect($rejected->status)->toBe(OrderStatus::Rejected)
        ->and($rejected->version)->toBe(2)
        ->and($rejected->decided_by)->toBe($this->deputy->id)
        ->and($rejected->decision_reason_code)->toBe('AUTRE')
        ->and($rejected->decision_comment)->toBe('Commande en double avec celle de 9 h')
        ->and($rejected->is_adjusted)->toBeFalse()
        // Quantités validées nulles, totaux de la demande (I-74), aucun compteur touché.
        ->and($rejected->lines()->whereNotNull('validated_qty')->exists())->toBeFalse()
        ->and($rejected->total_ht)->toBe('38551.50')
        ->and(($this->counter)($this->orange))->toBe(0)
        ->and($rejected->history()->get()->last()->only(['to_status', 'actor', 'user_id', 'comment']))->toBe([
            'to_status' => OrderStatus::Rejected, 'actor' => OrderActor::Regional, 'user_id' => $this->deputy->id,
            'comment' => 'Commande en double avec celle de 9 h',
        ])
        ->and(AuditLog::query()->where('event', 'rejected')->sole()->properties->all())->toEqual([
            'number' => $order->number, 'version' => 2, 'reason' => 'AUTRE', 'comment' => 'Commande en double avec celle de 9 h',
        ])
        ->and(app(OrderUsage::class)->forClient($this->regionDay->business_day_id, $this->a->id))->toBe([]);
    Event::assertDispatched(OrderRejected::class, fn (OrderRejected $e) => $e->orderId === $order->id && $e->clientId === $this->a->id
        && $e->version === 2 && $e->actorId === $this->deputy->id);

    // Avant l'heure limite de commande, le client peut renvoyer une commande (RG-C04, I-94).
    $again = ($this->submit)($this->accountA, [[$this->orange, 50]]);
    expect($again->status)->toBe(OrderStatus::Submitted);

    // Heure limite de validation bloquante dépassée, client bloqué : le rejet reste possible (I-70, I-71).
    $other = ($this->submit)($this->accountB, [[$this->pomme, 1]]);
    $this->b->forceFill(['status' => 'BLOCKED'])->save();
    ($this->settings)(['cutoffs' => ['validation_blocking' => true]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 16:00', 'Africa/Algiers'));
    expect($this->decisions->reject($this->titular, $other, 1, 'CLIENT_BLOQUE', null)->status)->toBe(OrderStatus::Rejected);

    // Journée clôturée : plus de rejet.
    BusinessDay::query()->whereKey($this->regionDay->business_day_id)->update(['status' => 'CLOSED']);
    expect(($this->problem)(fn () => $this->decisions->reject($this->titular, $again, 1, 'DOUBLON', null))->errorCode->value)->toBe('DAY_NOT_OPEN');
});

it('takes the locks in the order of I-67: client, day, region day, order, then limits and counters by product', function () {
    $order = ($this->submit)($this->accountA, [[$this->pomme, 3], [$this->orange, 40], [$this->lben, 7]]);
    $queries = [];
    $inside = false;
    Event::listen(TransactionBeginning::class, function () use (&$inside): void {
        $inside = true;
    });
    DB::listen(function ($query) use (&$queries, &$inside): void {
        if ($inside) {
            $queries[] = strtolower($query->sql);
        }
    });

    ($this->validate)($order, [[$this->lben, 7], [$this->orange, 40], [$this->pomme, 3]]);

    // Instruction et table de chaque requête : « select clients », « update orders »…
    $steps = array_map(fn (string $sql) => preg_match('/^(select)\b.*?\bfrom ["`]?(\w+)|^(update|insert into) ["`]?(\w+)/', $sql, $m) === 1
        ? ($m[1] !== '' ? 'select '.$m[2] : explode(' ', $m[3])[0].' '.$m[4])
        : $sql, $queries);
    expect(array_slice($steps, 0, 4))->toBe(['select clients', 'select business_days', 'select region_days', 'select orders']);
    $bump = array_search('update orders', $steps, true);
    $limits = array_search('select client_limits', $steps, true);
    $counters = array_search('select region_quotas', $steps, true);
    expect($bump)->toBeInt()
        ->and($limits)->toBeGreaterThan($bump)
        ->and($counters)->toBeGreaterThan($limits);
    // Compteurs mis à jour par produit croissant (orange, pomme, lben : identifiants croissants).
    $increments = array_map(fn (string $sql) => (int) preg_replace('/^.*?validated_qty["`]? \+ (\d+).*$/', '$1', $sql), array_values(array_filter(
        $queries,
        fn (string $sql) => preg_match('/^update ["`]?region_quotas/', $sql) === 1,
    )));
    expect($increments)->toBe([40, 3, 7]);
    if (DB::getDriverName() === 'mysql') {
        expect($queries[0])->toEndWith('for update')
            ->and($queries[1])->toEndWith('lock in share mode')
            ->and($queries[2])->toEndWith('lock in share mode')
            ->and($queries[3])->toEndWith('for update');
    }
});

it('refuses a quota decrease or a borne under the quantity validated for real (RG-Q05, RG-B07, I-2)', function () {
    validateOrder(($this->submit)($this->accountA, [[$this->orange, 30], [$this->pomme, 20]]));
    $adv = userWithRole(Role::Adv);
    $quotas = app(QuotaBook::class);

    // Quota de la pomme : 19 < V = 20 refusé, 20 accepté (T07).
    $quotas->saveDrafts($this->regionDay->businessDay, [['region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'quantity' => 19]], $adv);
    $e = ($this->problem)(fn () => $quotas->publish($this->regionDay->businessDay, [$this->centre->id], $adv));
    expect($e->errorCode->value)->toBe('QUOTA_BELOW_VALIDATED')->and($e->extra['violations'][0])->toMatchArray(['validated' => 20, 'requested' => 19]);
    $quotas->saveDrafts($this->regionDay->businessDay, [['region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'quantity' => 20]], $adv);
    $quotas->publish($this->regionDay->businessDay, [$this->centre->id], $adv);
    expect(RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $this->pomme->id)->value('quantity'))->toBe(20);

    // Borne de A sur l'orange : 29 < V(c,p) = 30 refusée en brouillon, 30 acceptée.
    $limits = app(LimitBook::class);
    expect(($this->invalid)(fn () => $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 29]], $this->titular)))
        ->toHaveKey('cells.0.limit');
    $limits->saveDrafts($this->regionDay, [['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 30]], $this->titular);
    expect($limits->publish($this->regionDay, $this->titular)->version)->toBe(2);
});
