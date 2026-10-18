<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\Product;
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
// nommés) et droits d'action.

beforeEach(function () {
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
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
    OrderStatusHistory::create(['order_id' => $this->validated->id, 'from_status' => null, 'to_status' => OrderStatus::Submitted, 'version' => 1, 'user_id' => $this->second->id, 'created_at' => now()]);
    $at('2026-10-07 11:00');
    $this->validated->forceFill(['decided_at' => now()])->save();
    OrderStatusHistory::create([
        'order_id' => $this->validated->id, 'from_status' => OrderStatus::Submitted, 'to_status' => OrderStatus::Validated, 'version' => 2,
        'user_id' => $this->regional->id, 'comment' => 'Quota limité', 'created_at' => now(),
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
            'decision_comment' => 'Quota limité',
            'modifiable_until' => null,
            'lines' => [[
                'product_id' => $this->orange->id, 'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => $this->orange->short_name,
                'sales_unit_label' => $this->orange->sales_unit_label, 'photo' => null,
                'requested_qty' => 20, 'validated_qty' => 15, 'shipped_qty' => null,
                // Montant sur la quantité validée (RG-P04).
                'unit_price_ht' => 900.0, 'vat_rate' => 19.0, 'amount_ht' => 13500.0, 'adjustment_reason_code' => 'QUOTA_INSUFFISANT',
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
