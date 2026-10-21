<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Orders\OrderStatus;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrderCancelledByAdv;
use App\Events\OrderRejected;
use App\Events\OrdersExpired;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\OrderValidated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// « Mes commandes » : GET /client/orders et GET /client/orders/{id}
// (EF-CLT-04 ; contrat du lot 5, §3) — commandes du client, tous ses
// comptes, toutes journées, plus récentes d'abord, filtres et pagination ;
// détail avec lignes, décision, historique (comptes du personnel non
// nommés) et droits d'action. Lot 6 : motifs avec libellé, ajustement et son
// commentaire, rejet, annulation de l'ADV, expédition (quantités, écart, bon
// de livraison visibles une fois la commande expédiée seulement, I-79) ;
// RG-C09 : jamais Q, R ni V(r,p).

beforeEach(function () {
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
        OrderValidated::class, OrderRejected::class, OrderCancelledByAdv::class, OrdersProcessed::class, OrderShippingUpdated::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->account = userWithRole(Role::Client, ['client_id' => $this->a->id, 'name' => 'Gérant C0012']);
    $this->second = userWithRole(Role::Client, ['client_id' => $this->a->id, 'name' => 'Vendeur C0012']);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->regional = userWithRole(Role::Regional, ['name' => 'Karim Régional']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'vat_rate' => '19.00']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'vat_rate' => '19.00']);
    app(Settings::class)->update(['orders' => ['max_per_day' => 3]], null);
    $this->submit = fn (User $by, array $lines) => app(ClientOrders::class)->submit(
        $by,
        (string) Str::uuid(),
        BusinessClock::today(),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];
    $at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse($moment, 'Africa/Algiers'));

    // Mercredi 7 : une commande expirée à la clôture, une commande validée avec ajustement (lot 6).
    $at('2026-10-07 09:00');
    $day7 = openOrderingDay('2026-10-07', $this->centre, [[$this->orange, 500], [$this->pomme, 300]], [[$this->a, $this->orange, 50]]);
    $this->expired = ($this->submit)($this->account, [[$this->orange, 10]]);
    $at('2026-10-07 10:00');
    $this->validated = Order::factory()->validated()->create([
        'number' => 'RMK-261007-00002', 'business_day_id' => $day7->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $this->a->id,
        'submitted_by' => $this->second->id, 'client_comment' => 'Avant 10 h', 'is_adjusted' => true,
        'decided_by' => $this->regional->id, 'decision_comment' => 'Quota limité',
        'total_ht' => '13500.00', 'total_vat' => '2565.00', 'total_ttc' => '16065.00', 'version' => 2,
    ]);
    OrderLine::factory()->for($this->validated)->create([
        'product_id' => $this->orange->id, 'requested_qty' => 20, 'validated_qty' => 15, 'unit_price_ht' => '900.00', 'vat_rate' => '19.00',
        'adjustment_reason_code' => 'QUOTA_INSUFFISANT',
    ]);
    OrderStatusHistory::create(['order_id' => $this->validated->id, 'from_status' => null, 'to_status' => OrderStatus::Submitted, 'version' => 1, 'user_id' => $this->second->id, 'actor' => OrderActor::Client, 'created_at' => now()]);
    $at('2026-10-07 11:00');
    $this->validated->forceFill(['decided_at' => now()])->save();
    OrderStatusHistory::create([
        'order_id' => $this->validated->id, 'from_status' => OrderStatus::Submitted, 'to_status' => OrderStatus::Validated, 'version' => 2,
        'user_id' => $this->regional->id, 'actor' => OrderActor::Regional, 'comment' => 'Quota limité', 'created_at' => now(),
    ]);
    app(DayCalendar::class)->close($day7->businessDay);

    // Jeudi 8 : une commande envoyée (09:30), une annulée (09:40) ; une commande de B.
    $at('2026-10-08 09:30');
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]], [[$this->a, $this->orange, 50]]);
    $this->submitted = ($this->submit)($this->account, [[$this->orange, 5], [$this->pomme, 2]]);
    ($this->submit)($this->accountB, [[$this->pomme, 1]]);
    $at('2026-10-08 09:40');
    $this->cancelled = ($this->submit)($this->second, [[$this->pomme, 4]]);
    app(ClientOrders::class)->cancel($this->second, $this->cancelled->public_id, 1);

    $this->index = fn (string $query = '') => $this->actingAs($this->account)->getJson('/api/v1/client/orders'.$query);
    $this->show = fn (Order $order) => $this->actingAs($this->account)->getJson("/api/v1/client/orders/{$order->public_id}");
    $this->summary = function (Order $order, bool $canModify, bool $canCancel): array {
        $order->refresh();

        return [
            'id' => $order->public_id, 'number' => $order->number, 'day' => $order->businessDay->dayKey(),
            'status' => $order->status->value, 'status_label' => $order->status->label(), 'version' => $order->version,
            'submitted_at' => $order->submitted_at->toIso8601String(),
            // Montant tel que le relit json_decode : 9000 pour 9 000,00.
            'total_ht' => json_decode(json_encode((float) $order->total_ht)), 'total_ttc' => json_decode(json_encode((float) $order->total_ttc)),
            'lines_count' => $order->lines()->count(), 'is_adjusted' => $order->is_adjusted,
            'can_modify' => $canModify, 'can_cancel' => $canCancel,
        ];
    };
});

describe('GET /client/orders', function () {
    it('lists the orders of the client, every account and day, newest first, with the actions allowed', function () {
        $response = ($this->index)()->assertOk();

        expect($response->json('data'))->toBe([
            ($this->summary)($this->cancelled, false, false),
            ($this->summary)($this->submitted, true, true),
            ($this->summary)($this->validated, false, false),
            ($this->summary)($this->expired, false, false),
        ])
            ->and($response->json('meta'))->toMatchArray(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 4, 'from' => 1, 'to' => 4])
            ->and($response->json('data.2'))->toMatchArray(['number' => 'RMK-261007-00002', 'status' => 'VALIDATED', 'is_adjusted' => true, 'total_ht' => 13500])
            ->and($response->json('data.3'))->toMatchArray(['day' => '2026-10-07', 'status' => 'EXPIRED', 'version' => 2]);
    });

    it('paginates by 25, 100 at most', function () {
        ($this->index)('?per_page=2')->assertOk()
            ->assertJsonPath('data.*.id', [$this->cancelled->public_id, $this->submitted->public_id])
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 4);
        ($this->index)('?per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.*.id', [$this->validated->public_id, $this->expired->public_id])
            ->assertJsonPath('meta.current_page', 2);
        ($this->index)('?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    });

    it('filters by day and by a list of statuses', function (string $query, array $orders) {
        ($this->index)($query)->assertOk()->assertJsonPath('data.*.id', array_map(fn (string $name) => $this->{$name}->public_id, $orders));
    })->with([
        'journée' => ['?filter[day]=2026-10-07', ['validated', 'expired']],
        'statut' => ['?filter[status]=SUBMITTED', ['submitted']],
        'liste de statuts, minuscules' => ['?filter[status]=expired,validated', ['validated', 'expired']],
        'journée et statut' => ['?filter[day]=2026-10-08&filter[status]=CANCELLED', ['cancelled']],
        'journée sans commande' => ['?filter[day]=2026-10-11', []],
        'statut sans commande' => ['?filter[status]=REJECTED,DELIVERED', []],
    ]);

    it('answers 422 to an invalid filter, an unknown filter or a sort', function (string $query, string $field) {
        $response = ($this->index)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        if ($field !== '') {
            $response->assertJsonStructure(['errors' => [$field]]);
        }
    })->with([
        'statut inconnu' => ['?filter[status]=SUBMITTED,DRAFT', 'filter.status'],
        'statut imbriqué' => ['?filter[status][0][]=SUBMITTED', 'filter.status'],
        'journée mal formée' => ['?filter[day]=08/10/2026', 'filter.day'],
        'plusieurs journées' => ['?filter[day]=2026-10-07,2026-10-08', 'filter.day'],
        'journée imbriquée' => ['?filter[day][a][]=2026-10-07', 'filter.day'],
        'filtre inconnu' => ['?filter[client_id]=1', ''],
        'tri' => ['?sort=-submitted_at', ''],
    ]);

    it('lists nothing for a client without orders', function () {
        $other = Client::factory()->create(['region_id' => $this->centre->id]);

        $this->actingAs(userWithRole(Role::Client, ['client_id' => $other->id]))->getJson('/api/v1/client/orders')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    });

    it('reads the window of a day and region once per page', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->index)('?filter[status]=SUBMITTED')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries(); // Réchauffe les caches (permissions, paramètres).
        $few = $queries();
        Order::factory()->count(10)->create(['business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $this->a->id]);

        expect($queries())->toBe($few)
            ->and(($this->index)('?filter[status]=SUBMITTED')->json('data.*.can_modify'))->toBe(array_fill(0, 11, true));
    });
});

describe('GET /client/orders/{id}', function () {
    it('shows a decided order: lines with the validated quantities, decision and history without the staff names', function () {
        ($this->show)($this->validated)->assertOk()->assertExactJson([
            'id' => $this->validated->public_id,
            'number' => 'RMK-261007-00002',
            'day' => '2026-10-07',
            'status' => 'VALIDATED',
            'status_label' => 'Validée',
            'version' => 2,
            'submitted_at' => '2026-10-07T09:00:00+00:00',
            'total_ht' => 13500.0,
            'total_ttc' => 16065.0,
            'lines_count' => 1,
            'is_adjusted' => true,
            'can_modify' => false,
            'can_cancel' => false,
            'submitted_by' => ['id' => $this->second->id, 'name' => 'Vendeur C0012'],
            'comment' => 'Avant 10 h',
            'total_vat' => 2565.0,
            'decided_at' => '2026-10-07T10:00:00+00:00',
            'decision_reason_code' => null,
            'decision_reason' => null,
            'decision_comment' => 'Quota limité',
            'modifiable_until' => null,
            'lines' => [[
                'product_id' => $this->orange->id, 'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => $this->orange->short_name,
                'sales_unit_label' => $this->orange->sales_unit_label, 'photo' => null,
                'requested_qty' => 20, 'validated_qty' => 15, 'shipped_qty' => null,
                // Montant sur la quantité validée (RG-P04).
                'unit_price_ht' => 900.0, 'vat_rate' => 19.0, 'amount_ht' => 13500.0, 'adjustment_reason_code' => 'QUOTA_INSUFFISANT',
                'adjustment_reason' => ['code' => 'QUOTA_INSUFFISANT', 'label' => 'Quota insuffisant'], 'adjustment_comment' => null, 'shipping_gap_reason' => null,
            ]],
            'history' => [
                ['from' => null, 'to' => 'SUBMITTED', 'version' => 1, 'at' => '2026-10-07T09:00:00+00:00', 'actor' => 'CLIENT', 'by_name' => 'Vendeur C0012', 'comment' => null],
                // Le régional n'est pas nommé au client.
                ['from' => 'SUBMITTED', 'to' => 'VALIDATED', 'version' => 2, 'at' => '2026-10-07T10:00:00+00:00', 'actor' => 'REGIONAL', 'by_name' => null, 'comment' => 'Quota limité'],
            ],
        ]);
    });

    it('shows an expired order with the system in its history', function () {
        ($this->show)($this->expired)->assertOk()
            ->assertJsonPath('status', 'EXPIRED')
            ->assertJsonPath('modifiable_until', null)
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('history.1', [
                'from' => 'SUBMITTED', 'to' => 'EXPIRED', 'version' => 2, 'at' => '2026-10-07T10:00:00+00:00',
                'actor' => 'SYSTEM', 'by_name' => null, 'comment' => null,
            ]);
    });

    it('gives the actions allowed on a submitted order and its cutoff (RG-C05, I-53)', function () {
        ($this->show)($this->submitted)->assertOk()
            ->assertJsonPath('can_modify', true)
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('modifiable_until', '2026-10-08T12:00:00+00:00')
            ->assertJsonPath('submitted_by', ['id' => $this->account->id, 'name' => 'Gérant C0012']);

        // Client bloqué : annulation seulement.
        $this->a->forceFill(['status' => 'BLOCKED'])->save();
        ($this->show)($this->submitted)->assertJsonPath('can_modify', false)->assertJsonPath('can_cancel', true);

        // Heure limite passée : plus rien, l'heure limite reste indiquée.
        $this->a->forceFill(['status' => 'ACTIVE'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'Africa/Algiers'));
        ($this->show)($this->submitted)
            ->assertJsonPath('can_modify', false)
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('modifiable_until', '2026-10-08T12:00:00+00:00');
    });

    it('shows the order to every account of the client, and to no other client', function () {
        $this->actingAs($this->second)->getJson("/api/v1/client/orders/{$this->submitted->public_id}")->assertOk()
            ->assertJsonPath('submitted_by.name', 'Gérant C0012');
        expectProblem($this->actingAs($this->accountB)->getJson("/api/v1/client/orders/{$this->submitted->public_id}"), 404, 'NOT_FOUND');
    });
});

describe('decisions and shipping as the client sees them (lot 6)', function () {
    beforeEach(function () {
        $this->adv = userWithRole(Role::Adv);
        // Décision du régional sur la commande envoyée du jeudi 8 (version courante).
        $this->validate = fn (Order $order, array $lines): Order => app(OrderDecisions::class)->validate($this->regional, $order, $order->fresh()->version, $lines);
        $this->validateAll = fn (Order $order): Order => ($this->validate)($order, [
            ['product_id' => $this->orange->id, 'validated_qty' => 5],
            ['product_id' => $this->pomme->id, 'validated_qty' => 2],
        ]);
        $this->process = fn (OrderStatus $to, Order $order) => app(AdvOrders::class)->transition($this->adv, $to, [['id' => $order->public_id, 'version' => $order->fresh()->version]]);
        // Date d'expédition et bon de livraison : clés absentes avant l'expédition.
        $this->hasShipping = fn (array $json): bool => array_key_exists('shipped_at', $json) || array_key_exists('delivery_note_ref', $json);
        // RG-C09 : ni quota, ni reste, ni borne, ni validé de la région, ni nom du personnel.
        $this->noQuotaLeak = function (array $json): void {
            $forbidden = ['context', 'limit', 'client_validated', 'region_quota', 'region_validated', 'region_remaining', 'max_validatable', 'quota', 'remaining', 'decided_by'];
            $walk = function (array $node) use (&$walk, $forbidden): void {
                foreach ($node as $key => $value) {
                    expect(in_array($key, $forbidden, true))->toBeFalse("clé interdite au client : {$key}");
                    if (is_array($value)) {
                        $walk($value);
                    }
                }
            };
            $walk($json);
            expect(json_encode($json))->not->toContain('Karim');
        };
    });

    it('shows an adjusted validation: reduced quantity, reason with its label and the comment of the regional (I-72, I-73)', function () {
        $order = ($this->validate)($this->submitted, [
            ['product_id' => $this->orange->id, 'validated_qty' => 3, 'reason_code' => 'QUOTA_INSUFFISANT', 'comment' => 'Reste région limité'],
            ['product_id' => $this->pomme->id, 'validated_qty' => 2],
        ]);

        $response = ($this->show)($order)->assertOk();

        expect($response->json())->toMatchArray([
            'status' => 'VALIDATED', 'status_label' => 'Validée', 'version' => 2, 'is_adjusted' => true,
            // Totaux recalculés sur les quantités validées : (3 + 2) × 900 DA HT, TVA 19 %.
            'total_ht' => 4500.0, 'total_vat' => 855.0, 'total_ttc' => 5355.0,
            'decision_reason' => null, 'decision_reason_code' => null, 'can_modify' => false, 'can_cancel' => false,
        ])
            // Rien n'est expédié : ni date, ni bon de livraison (I-79).
            ->and(($this->hasShipping)($response->json()))->toBeFalse()
            ->and($response->json('lines.0'))->toMatchArray([
                'sku' => 'P0042', 'requested_qty' => 5, 'validated_qty' => 3, 'shipped_qty' => null, 'amount_ht' => 2700.0,
                'adjustment_reason_code' => 'QUOTA_INSUFFISANT',
                'adjustment_reason' => ['code' => 'QUOTA_INSUFFISANT', 'label' => 'Quota insuffisant'],
                'adjustment_comment' => 'Reste région limité', 'shipping_gap_reason' => null,
            ])
            ->and($response->json('lines.1'))->toMatchArray([
                'sku' => 'P0057', 'requested_qty' => 2, 'validated_qty' => 2, 'amount_ht' => 1800.0,
                'adjustment_reason_code' => null, 'adjustment_reason' => null, 'adjustment_comment' => null,
            ])
            ->and($response->json('history.1'))->toMatchArray(['from' => 'SUBMITTED', 'to' => 'VALIDATED', 'actor' => 'REGIONAL', 'by_name' => null]);
        ($this->noQuotaLeak)($response->json());
    });

    it('keeps the label of a reason deactivated since, and the code itself when the reason is unknown', function () {
        $order = ($this->validate)($this->submitted, [
            ['product_id' => $this->orange->id, 'validated_qty' => 3, 'reason_code' => 'BORNE_ATTEINTE'],
            ['product_id' => $this->pomme->id, 'validated_qty' => 2],
        ]);
        ReasonCode::query()->where('type', 'ADJUSTMENT')->where('code', 'BORNE_ATTEINTE')->update(['is_active' => false]);
        app(ReasonCodes::class)->flush();

        ($this->show)($order)->assertOk()
            ->assertJsonPath('lines.0.adjustment_reason', ['code' => 'BORNE_ATTEINTE', 'label' => 'Borne du client atteinte']);

        // Un code absent du référentiel reste lisible : le code, jamais une erreur.
        OrderLine::query()->where('order_id', $order->id)->where('product_id', $this->orange->id)->update(['adjustment_reason_code' => 'ANCIEN_CODE']);
        ($this->show)($order)->assertOk()
            ->assertJsonPath('lines.0.adjustment_reason_code', 'ANCIEN_CODE')
            ->assertJsonPath('lines.0.adjustment_reason', ['code' => 'ANCIEN_CODE', 'label' => 'ANCIEN_CODE']);
    });

    it('shows a rejection with its reason label and comment, nothing validated, and lets the client order again (I-94)', function () {
        $order = app(OrderDecisions::class)->reject($this->regional, $this->submitted, 1, 'QUOTA_EPUISE', 'Plus de quota cette semaine');

        $response = ($this->show)($order)->assertOk();

        expect($response->json())->toMatchArray([
            'status' => 'REJECTED', 'status_label' => 'Rejetée', 'version' => 2, 'is_adjusted' => false,
            'decision_reason_code' => 'QUOTA_EPUISE', 'decision_reason' => ['code' => 'QUOTA_EPUISE', 'label' => 'Quota épuisé'],
            'decision_comment' => 'Plus de quota cette semaine', 'can_modify' => false, 'can_cancel' => false,
            // Rejet : totaux inchangés sur les quantités demandées (I-74).
            'total_ht' => 5 * 900.0 + 2 * 900.0,
        ])
            ->and($response->json('decided_at'))->not->toBeNull()
            ->and($response->json('lines.*.validated_qty'))->toBe([null, null])
            ->and($response->json('lines.*.shipped_qty'))->toBe([null, null])
            ->and($response->json('history.1'))->toMatchArray(['to' => 'REJECTED', 'actor' => 'REGIONAL', 'by_name' => null, 'comment' => 'Plus de quota cette semaine']);
        ($this->noQuotaLeak)($response->json());

        // Le rejet libère la commande du jour : le client peut renvoyer avant l'heure limite (RG-C01, RG-C04).
        app(Settings::class)->update(['orders' => ['max_per_day' => 1]], null);
        $this->actingAs($this->account)->getJson('/api/v1/client/today')->assertOk()->assertJsonPath('can_submit', true);
        $again = ($this->submit)($this->account, [[$this->orange, 2]]);
        expect($again->status)->toBe(OrderStatus::Submitted);
    });

    it('shows an exceptional cancellation by the ADV with its reason label, the validated quantities and the ADV in the history (I-75)', function () {
        $validated = ($this->validateAll)($this->submitted);
        $order = app(AdvOrders::class)->cancel($this->adv, $validated, $validated->fresh()->version, 'RUPTURE', 'Produit indisponible en dépôt');

        $response = ($this->show)($order)->assertOk();

        expect($response->json())->toMatchArray([
            'status' => 'CANCELLED', 'status_label' => 'Annulée', 'version' => 3, 'is_adjusted' => false,
            // Les quantités et les totaux validés sont gardés (I-74) ; le motif de l'annulation remplace celui de la décision.
            'total_ht' => 6300.0,
            'decision_reason_code' => 'RUPTURE', 'decision_reason' => ['code' => 'RUPTURE', 'label' => 'Rupture de stock'],
            'decision_comment' => 'Produit indisponible en dépôt', 'can_modify' => false, 'can_cancel' => false,
        ])
            ->and($response->json('decided_at'))->not->toBeNull()
            ->and($response->json('lines.*.validated_qty'))->toBe([5, 2])
            ->and($response->json('history.2'))->toMatchArray([
                'from' => 'VALIDATED', 'to' => 'CANCELLED', 'actor' => 'ADV', 'by_name' => null, 'comment' => 'Produit indisponible en dépôt',
            ]);
        ($this->noQuotaLeak)($response->json());
    });

    it('does not label a cancellation by the client itself', function () {
        ($this->show)($this->cancelled)->assertOk()
            ->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('decision_reason', null)
            ->assertJsonPath('decision_reason_code', null)
            ->assertJsonPath('history.1.actor', 'CLIENT');
    });

    it('hides the shipped quantities, the gaps, the delivery note and the date until the order is shipped (I-79)', function () {
        $order = ($this->validateAll)($this->submitted);
        ($this->process)(OrderStatus::InPreparation, $order);
        // L'ADV saisit un écart et le bon de livraison pendant la préparation : le client ne le voit pas encore.
        app(AdvOrders::class)->updateShipping(
            $this->adv,
            $order,
            $order->fresh()->version,
            [['product_id' => $this->pomme->id, 'shipped_qty' => 1, 'reason_code' => 'CASSE']],
            'BL-0042',
            true,
        );
        $hidden = function (string $status) use ($order): void {
            $response = ($this->show)($order)->assertOk();
            expect($response->json('status'))->toBe($status)
                ->and(($this->hasShipping)($response->json()))->toBeFalse()
                ->and($response->json('lines.*.shipped_qty'))->toBe([null, null])
                ->and($response->json('lines.*.shipping_gap_reason'))->toBe([null, null])
                ->and($response->json('lines.*.validated_qty'))->toBe([5, 2]);
            ($this->noQuotaLeak)($response->json());
        };
        $hidden('IN_PREPARATION');
        ($this->process)(OrderStatus::Prepared, $order);
        $hidden('PREPARED');

        $this->travel(5)->minutes();
        ($this->process)(OrderStatus::Shipped, $order);
        $response = ($this->show)($order)->assertOk();

        expect($response->json())->toMatchArray([
            'status' => 'SHIPPED', 'status_label' => 'Expédiée', 'version' => 6,
            'shipped_at' => now()->toIso8601String(), 'delivery_note_ref' => 'BL-0042',
            'can_modify' => false, 'can_cancel' => false,
        ])
            ->and($response->json('lines.*.shipped_qty'))->toBe([5, 1])
            ->and($response->json('lines.*.validated_qty'))->toBe([5, 2])
            ->and($response->json('lines.0.shipping_gap_reason'))->toBeNull()
            ->and($response->json('lines.1.shipping_gap_reason'))->toBe(['code' => 'CASSE', 'label' => 'Casse'])
            // Chronologie réelle : préparation, préparée, expédiée ; l'ADV n'est pas nommé.
            ->and($response->json('history.*.to'))->toBe(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED'])
            ->and($response->json('history.*.actor'))->toBe(['CLIENT', 'REGIONAL', 'ADV', 'ADV', 'ADV'])
            ->and($response->json('history.*.by_name'))->toBe(['Gérant C0012', null, null, null, null]);
        ($this->noQuotaLeak)($response->json());
    });

    it('keeps the shipping visible once the order is delivered', function () {
        $order = Order::factory()->shipped('BL-0099')->status(OrderStatus::Delivered)->withLines([[$this->orange, 10, 10]])->create([
            'business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $this->a->id,
        ]);

        ($this->show)($order)->assertOk()
            ->assertJsonPath('status', 'DELIVERED')
            ->assertJsonPath('delivery_note_ref', 'BL-0099')
            ->assertJsonPath('shipped_at', $order->shipped_at->toIso8601String())
            ->assertJsonPath('lines.0.shipped_qty', 10);
    });

    it('lists decided orders with the same summary, whatever their lines (no shipping, no reason)', function () {
        $order = ($this->validateAll)($this->submitted);
        ($this->process)(OrderStatus::InPreparation, $order);

        $row = collect(($this->index)()->assertOk()->json('data'))->firstWhere('id', $order->public_id);

        expect($row)->toBe(($this->summary)($order, false, false))
            ->and($row['status'])->toBe('IN_PREPARATION');
    });

    it('reads a shipped order in a bounded number of queries, whatever its lines', function () {
        $products = Product::factory()->count(8)->create(['vat_rate' => '19.00']);
        $make = fn (int $count) => Order::factory()->shipped('BL-0001')
            ->withLines($products->take($count)->map(fn (Product $product) => [$product, 10, 8])->all())
            ->create(['business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $this->a->id]);
        $few = $make(1);
        $many = $make(8);
        $queries = function (Order $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->show)($order)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries($few); // Réchauffe les caches (permissions, paramètres).

        expect($queries($many))->toBe($queries($few));
    });
});
