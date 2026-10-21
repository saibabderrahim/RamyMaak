<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Orders\OrderStatus;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Referentials\AssignmentKind;
use App\Domain\Referentials\ClientStatus;
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
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Depot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

// Suivi de la journée : GET /adv/days/{day}/monitoring (EF-ADV-03 ;
// contrat du lot 6, §6, I-89, I-96) — par région : statut, clients notifiés
// et ayant commandé, commandes par statut, dernière validation, alertes de
// retard ; par produit : Q, V, en attente, reste, régions épuisées ; cellules
// région × produit. Périmètre RG-S05, réponse sans enveloppe, requêtes en
// nombre constant quel que soit le volume.

beforeEach(function () {
    $this->at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse($moment, 'Africa/Algiers'));
    ($this->at)('2026-10-08 07:00');
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderCancelled::class, OrdersExpired::class,
        OrderValidated::class, OrdersProcessed::class, OrderShippingUpdated::class, OrderCancelledByAdv::class,
    ]);
    $this->depot = Depot::factory()->create(['code' => 'BLIDA', 'name' => 'Dépôt de Blida']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre', 'depot_id' => $this->depot->id]);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->c = Client::factory()->create(['code' => 'C0099', 'region_id' => $this->centre->id]);
    Client::factory()->create(['code' => 'C0098', 'region_id' => $this->centre->id, 'status' => ClientStatus::Blocked]);
    $this->e = Client::factory()->create(['code' => 'C0300', 'region_id' => $this->est->id]);
    Client::factory()->create(['code' => 'C0301', 'region_id' => $this->est->id]);
    Client::factory()->create(['code' => 'C0302', 'region_id' => $this->est->id, 'status' => ClientStatus::Inactive]);
    $this->boissons = ProductFamily::factory()->create(['code' => 'BOISSONS', 'name' => 'Boissons', 'sort_order' => 1]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'short_name' => 'Orange 1 L', 'family_id' => $this->boissons->id, 'sort_order' => 1]);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'short_name' => 'Pomme 1 L', 'family_id' => $this->boissons->id, 'sort_order' => 2]);
    app(Settings::class)->update(['orders' => ['max_per_day' => 5]], null);
    $this->adv = userWithRole(Role::Adv);
    $this->accounts = [];
    $this->submit = function (Client $client, array $lines): Order {
        $this->accounts[$client->id] ??= userWithRole(Role::Client, ['client_id' => $client->id]);

        return app(ClientOrders::class)->submit(
            $this->accounts[$client->id], (string) Str::uuid(), BusinessClock::parseDay('2026-10-08'),
            array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines), null,
        )['order'];
    };

    // Centre et Est : quotas publiés et répartition envoyée à 07:40 ; Ouest : quotas publiés seulement.
    $this->centreDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 100], [$this->pomme, 50]]);
    $this->estDay = openOrderingDay('2026-10-08', $this->est, [[$this->orange, 50]]);
    $day = BusinessDay::forDay('2026-10-08');
    app(QuotaBook::class)->saveDrafts($day, [['region_id' => $this->ouest->id, 'product_id' => $this->orange->id, 'quantity' => 30]], $this->adv);
    app(QuotaBook::class)->publish($day, [$this->ouest->id], $this->adv);
    $this->day = $day;

    // Centre : A et B ont commandé du jus d'orange (validé, puis A en préparation) ; C a été rejeté ; A et B ont des commandes envoyées en pomme.
    ($this->at)('2026-10-08 08:00');
    $this->orderA = ($this->submit)($this->a, [[$this->orange, 40]]);
    $this->orderB = ($this->submit)($this->b, [[$this->orange, 60]]);
    ($this->at)('2026-10-08 08:20');
    $this->orderA = validateOrder($this->orderA);
    ($this->at)('2026-10-08 08:30');
    $this->orderB = validateOrder($this->orderB);
    ($this->at)('2026-10-08 08:40');
    app(AdvOrders::class)->transition($this->adv, OrderStatus::InPreparation, [['id' => $this->orderA->public_id, 'version' => 2]]);
    $this->sentA = ($this->submit)($this->a, [[$this->pomme, 10]]);
    $this->sentB = ($this->submit)($this->b, [[$this->pomme, 5]]);
    $rejected = ($this->submit)($this->c, [[$this->pomme, 3]]);
    app(OrderDecisions::class)->reject(RegionAssignment::query()->where('region_id', $this->centre->id)->firstOrFail()->user, $rejected, 1, 'DOUBLON', null);
    // Est : E a commandé, validé et expédié 20.
    ($this->at)('2026-10-08 08:50');
    $this->orderE = validateOrder(($this->submit)($this->e, [[$this->orange, 20]]));
    foreach ([OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped] as $status) {
        app(AdvOrders::class)->transition($this->adv, $status, [['id' => $this->orderE->public_id, 'version' => $this->orderE->fresh()->version]]);
    }

    ($this->at)('2026-10-08 09:30');
    $this->monitoring = fn (?User $as = null, string $day = '2026-10-08') => $this->actingAs($as ?? $this->adv)->getJson("/api/v1/adv/days/{$day}/monitoring");
    $this->region = fn (array $json, Region $region): array => collect($json['regions'])->firstWhere('region.id', $region->id);
    $this->product = fn (array $json, Product $product): array => collect($json['products'])->firstWhere('product_id', $product->id);
    $this->restricted = fn (Region $region): User => tap(userWithRole(Role::Adv), fn (User $user) => RegionAssignment::factory()->create([
        'region_id' => $region->id, 'user_id' => $user->id, 'kind' => AssignmentKind::Adv, 'starts_on' => '2026-10-01',
    ]));
});

describe('rights and perimeter', function () {
    it('lets the ADV and the Admin read the monitoring', function (string $role) {
        $user = $role === 'adv' ? $this->adv : admin();

        ($this->monitoring)($user)->assertOk()->assertJsonCount(3, 'regions');
    })->with(['adv', 'admin']);

    it('refuses with 403 the regional, a client account and an account without role', function (string $role) {
        $user = match ($role) {
            'regional' => userWithRole(Role::Regional),
            'client' => $this->accounts[$this->a->id],
            'none' => User::factory()->create(),
        };

        expectProblem(($this->monitoring)($user), 403, 'FORBIDDEN');
    })->with(['regional', 'client', 'none']);

    it('requires an authenticated session', function () {
        expectProblem($this->getJson('/api/v1/adv/days/2026-10-08/monitoring'), 401, 'UNAUTHENTICATED');
    });

    it('follows the dashboard.view permission of the roles, not only the roles', function (string $role) {
        SpatieRole::findByName($role)->revokePermissionTo('dashboard.view');
        $user = $role === 'adv' ? $this->adv : admin();

        expectProblem(($this->monitoring)($user), 403, 'FORBIDDEN');
    })->with(['adv', 'admin']);

    it('answers 404 for a day that is not open', function () {
        expectProblem(($this->monitoring)(null, '2026-10-20'), 404, 'NOT_FOUND');
        expectProblem(($this->monitoring)(null, 'bogus'), 404, 'NOT_FOUND');
    });

    it('limits a restricted ADV to the regions of its assignments, the totals and the matrix included (RG-S05)', function () {
        $json = ($this->monitoring)(($this->restricted)($this->est))->assertOk()->json();

        expect(array_column(array_column($json['regions'], 'region'), 'code'))->toBe(['EST'])
            ->and($json['totals']['orders']['SHIPPED'])->toBe(1)
            ->and($json['totals']['orders']['VALIDATED'])->toBe(0)
            ->and($json['totals']['clients'])->toBe(['notified' => 2, 'ordered' => 1])
            ->and(array_column($json['products'], 'sku'))->toBe(['P0042'])
            ->and($json['products'][0])->toMatchArray(['quota' => 50, 'validated' => 20, 'pending' => 0, 'remaining' => 30])
            ->and(array_keys($json['cells']))->toBe([$this->est->id]);
    });

    it('answers with an empty list of regions and an empty JSON object when nothing is in the perimeter', function () {
        $none = ($this->restricted)(Region::factory()->create(['code' => 'SUD', 'is_active' => false]));

        $response = ($this->monitoring)($none)->assertOk();

        expect($response->json('regions'))->toBe([])
            ->and($response->json('products'))->toBe([])
            ->and($response->json('totals'))->toBe(['orders' => array_fill_keys(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'REJECTED', 'CANCELLED', 'EXPIRED'], 0), 'clients' => ['notified' => 0, 'ordered' => 0]])
            ->and($response->getContent())->toContain('"cells":{}');
    });
});

describe('numbers', function () {
    it('is not wrapped in a data envelope (I-96) and gives the day, its status, its cutoffs and the generation time', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        expect($json)->not->toHaveKey('data')
            ->and(array_keys($json))->toBe(['day', 'status', 'cutoffs', 'generated_at', 'totals', 'regions', 'products', 'cells'])
            ->and($json['day'])->toBe('2026-10-08')
            ->and($json['status'])->toBe('OPEN')
            ->and($json['cutoffs'])->toBe(['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00'])
            ->and($json['generated_at'])->toBe('2026-10-08T08:30:00+00:00');
    });

    it('counts the orders by status, per region and in total', function () {
        $json = ($this->monitoring)()->assertOk()->json();
        $none = array_fill_keys(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'REJECTED', 'CANCELLED', 'EXPIRED'], 0);

        expect(($this->region)($json, $this->centre)['orders'])->toEqual(['SUBMITTED' => 2, 'VALIDATED' => 1, 'IN_PREPARATION' => 1, 'REJECTED' => 1] + $none)
            ->and(($this->region)($json, $this->est)['orders'])->toEqual(['SHIPPED' => 1] + $none)
            ->and(($this->region)($json, $this->ouest)['orders'])->toEqual($none)
            ->and($json['totals']['orders'])->toEqual(['SUBMITTED' => 2, 'VALIDATED' => 1, 'IN_PREPARATION' => 1, 'SHIPPED' => 1, 'REJECTED' => 1] + $none)
            // Les huit statuts, dans l'ordre de l'écran.
            ->and(array_keys($json['totals']['orders']))->toBe(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'REJECTED', 'CANCELLED', 'EXPIRED']);
    });

    it('counts the cancelled and expired orders too, with a cancellation after validation as a validation of the region', function () {
        app(AdvOrders::class)->cancel($this->adv, $this->orderB, 2, 'RUPTURE', null);
        $cancelled = ($this->submit)($this->c, [[$this->pomme, 1]]);
        app(ClientOrders::class)->cancel($this->accounts[$this->c->id], $cancelled->public_id, 1);
        Order::factory()->expired()->create(['business_day_id' => $this->day->id, 'client_id' => $this->c->id]);

        $json = ($this->monitoring)()->assertOk()->json();

        expect(($this->region)($json, $this->centre)['orders'])->toMatchArray(['VALIDATED' => 0, 'IN_PREPARATION' => 1, 'CANCELLED' => 2, 'EXPIRED' => 1, 'REJECTED' => 1])
            ->and($json['totals']['orders']['CANCELLED'])->toBe(2)
            // Le compteur du produit a rendu les 60 de B (V = 40 + 20).
            ->and(($this->product)($json, $this->orange))->toMatchArray(['validated' => 60]);
    });

    it('counts the clients notified (recipients of N-03) and the clients who ordered, per region and in total', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        // Centre : A, B, C actifs (le bloqué n'est pas notifié) ; A et B ont une commande comptée, C seulement une commande rejetée.
        expect(($this->region)($json, $this->centre)['clients'])->toBe(['notified' => 3, 'ordered' => 2])
            // Est : E et son voisin actif ; l'inactif est exclu.
            ->and(($this->region)($json, $this->est)['clients'])->toBe(['notified' => 2, 'ordered' => 1])
            // Ouest : répartition non envoyée, personne n'est notifié.
            ->and(($this->region)($json, $this->ouest)['clients'])->toBe(['notified' => 0, 'ordered' => 0])
            ->and($json['totals']['clients'])->toBe(['notified' => 5, 'ordered' => 3]);
    });

    it('counts a client with several counted orders once', function () {
        ($this->submit)($this->a, [[$this->pomme, 1]]);

        $json = ($this->monitoring)()->assertOk()->json();

        expect(($this->region)($json, $this->centre)['clients']['ordered'])->toBe(2)
            ->and(($this->region)($json, $this->centre)['orders']['SUBMITTED'])->toBe(3);
    });

    it('gives per region the status, the publication times, the cutoffs and the depot', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        expect(($this->region)($json, $this->centre))->toMatchArray([
            'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre', 'depot' => ['id' => $this->depot->id, 'code' => 'BLIDA', 'name' => 'Dépôt de Blida']],
            'status' => 'DISTRIBUTION_PUBLISHED',
            'status_label' => 'Répartition publiée',
            'quota_published_at' => '2026-10-08T06:00:00+00:00',
            'distribution_published_at' => '2026-10-08T06:00:00+00:00',
            'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00'],
            'alerts' => [],
        ])
            ->and(($this->region)($json, $this->est)['region']['depot'])->toBeNull()
            ->and(($this->region)($json, $this->ouest))->toMatchArray([
                'status' => 'QUOTA_PUBLISHED', 'status_label' => 'Quotas publiés', 'distribution_published_at' => null,
            ])
            ->and(array_column(array_column($json['regions'], 'region'), 'code'))->toBe(['CENTRE', 'EST', 'OUEST']);
    });

    it('gives the time of the last validation of each region, a rejection or a cancellation by the client not counting', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        expect(($this->region)($json, $this->centre)['last_validated_at'])->toBe('2026-10-08T07:30:00+00:00')
            ->and(($this->region)($json, $this->est)['last_validated_at'])->toBe('2026-10-08T07:50:00+00:00')
            ->and(($this->region)($json, $this->ouest)['last_validated_at'])->toBeNull();
    });

    it('gives per product the quota, the validated, the pending and the remaining quantities, and the sold-out regions (Q > 0 and R = 0)', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        // Orange : Q = 100 + 50 + 30, V = 100 (Centre : 40 + 60) + 20 (Est) ; épuisé au Centre seulement.
        expect(($this->product)($json, $this->orange))->toBe([
            'product_id' => $this->orange->id, 'sku' => 'P0042', 'short_name' => 'Orange 1 L', 'family' => ['id' => $this->boissons->id, 'name' => 'Boissons'],
            'quota' => 180, 'validated' => 120, 'pending' => 0, 'remaining' => 60, 'sold_out_regions' => [$this->centre->id],
        ])
            // Pomme : Q = 50 ; 15 en attente (commandes envoyées de A et B), rien de validé.
            ->and(($this->product)($json, $this->pomme))->toBe([
                'product_id' => $this->pomme->id, 'sku' => 'P0057', 'short_name' => 'Pomme 1 L', 'family' => ['id' => $this->boissons->id, 'name' => 'Boissons'],
                'quota' => 50, 'validated' => 0, 'pending' => 15, 'remaining' => 50, 'sold_out_regions' => [],
            ])
            ->and(array_column($json['products'], 'sku'))->toBe(['P0042', 'P0057']);
    });

    it('gives the matrix by region then product, with the pending quantities of the sent orders', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        expect($json['cells'])->toBe([
            $this->centre->id => [
                $this->orange->id => ['quota' => 100, 'validated' => 100, 'pending' => 0],
                $this->pomme->id => ['quota' => 50, 'validated' => 0, 'pending' => 15],
            ],
            $this->est->id => [$this->orange->id => ['quota' => 50, 'validated' => 20, 'pending' => 0]],
            $this->ouest->id => [$this->orange->id => ['quota' => 30, 'validated' => 0, 'pending' => 0]],
        ]);
    });

    it('orders the products by family, then by order and SKU, like the quota matrix, and lists only those with a quota or a pending quantity', function () {
        $fruits = ProductFamily::factory()->create(['code' => 'AAA', 'name' => 'Fruits', 'sort_order' => 0]);
        $mangue = Product::factory()->create(['sku' => 'P0900', 'family_id' => $fruits->id]);
        Product::factory()->create(['sku' => 'P0901']);
        app(QuotaBook::class)->saveDrafts($this->day, [['region_id' => $this->est->id, 'product_id' => $mangue->id, 'quantity' => 8]], $this->adv);
        app(QuotaBook::class)->publish($this->day, [$this->est->id], $this->adv);

        $json = ($this->monitoring)()->assertOk()->json();

        expect(array_column($json['products'], 'sku'))->toBe(['P0900', 'P0042', 'P0057']);
    });

    it('does not count a quota lowered to 0: no cell, no product', function () {
        app(QuotaBook::class)->saveDrafts($this->day, [['region_id' => $this->ouest->id, 'product_id' => $this->pomme->id, 'quantity' => 0]], $this->adv);
        app(QuotaBook::class)->publish($this->day, [$this->ouest->id], $this->adv);

        $json = ($this->monitoring)()->assertOk()->json();

        expect($json['cells'][$this->ouest->id])->toHaveKey($this->orange->id)->not->toHaveKey($this->pomme->id)
            ->and(($this->product)($json, $this->pomme)['quota'])->toBe(50);
    });

    it('gives a region without any quota an empty set of cells, so that the matrix can index every region', function () {
        $sud = Region::factory()->create(['code' => 'SUD']);
        $this->day->refresh();

        $response = ($this->monitoring)()->assertOk();

        expect(array_column(array_column($response->json('regions'), 'region'), 'code'))->toBe(['CENTRE', 'EST', 'OUEST', 'SUD'])
            ->and($response->json('cells.'.$sud->id))->toBe([])
            ->and($response->getContent())->toContain('"'.$sud->id.'":{}');
    });

    it('keeps a day with no order at all readable: zero everywhere, quotas in the matrix', function () {
        $day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-09'), $this->adv);
        app(QuotaBook::class)->saveDrafts($day, [['region_id' => $this->est->id, 'product_id' => $this->orange->id, 'quantity' => 12]], $this->adv);
        app(QuotaBook::class)->publish($day, [$this->est->id], $this->adv);

        $json = ($this->monitoring)(null, '2026-10-09')->assertOk()->json();

        expect($json['totals']['orders'])->toBe(array_fill_keys(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'REJECTED', 'CANCELLED', 'EXPIRED'], 0))
            ->and($json['totals']['clients'])->toBe(['notified' => 0, 'ordered' => 0])
            ->and($json['products'])->toHaveCount(1)
            ->and($json['products'][0])->toMatchArray(['quota' => 12, 'validated' => 0, 'pending' => 0, 'remaining' => 12])
            ->and($json['cells'][$this->est->id])->toBe([$this->orange->id => ['quota' => 12, 'validated' => 0, 'pending' => 0]]);
    });
});

describe('alerts', function () {
    it('raises no alert before the cutoffs', function () {
        $json = ($this->monitoring)()->assertOk()->json();

        expect(array_column($json['regions'], 'alerts'))->toBe([[], [], []]);
    });

    it('raises DISTRIBUTION_LATE when the quotas are published, the distribution is not sent and its cutoff is passed', function () {
        ($this->at)('2026-10-08 10:30');
        $json = ($this->monitoring)()->assertOk()->json();

        // Ouest : quotas publiés, répartition non envoyée ; Centre et Est l'ont envoyée.
        expect(($this->region)($json, $this->ouest)['alerts'])->toBe(['DISTRIBUTION_LATE'])
            ->and(($this->region)($json, $this->centre)['alerts'])->toBe([])
            ->and(($this->region)($json, $this->est)['alerts'])->toBe([]);
    });

    it('does not raise it for a region whose quotas are not published, nor before the cutoff of the region extended by the ADV (RG-A05)', function () {
        $sud = Region::factory()->create(['code' => 'SUD']);
        app(DayCalendar::class)->syncRegions($this->day);
        $ouestDay = RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $this->ouest->id)->firstOrFail();
        app(DayCalendar::class)->extendCutoff($ouestDay, CutoffKind::Distribution, '11:30', $this->adv);

        ($this->at)('2026-10-08 10:30');
        $json = ($this->monitoring)()->assertOk()->json();

        expect(($this->region)($json, $this->ouest)['alerts'])->toBe([])
            ->and(($this->region)($json, $this->ouest)['cutoffs']['distribution'])->toBe('11:30')
            ->and(($this->region)($json, $sud)['alerts'])->toBe([]);

        ($this->at)('2026-10-08 11:30');
        expect(($this->region)(($this->monitoring)()->json(), $this->ouest)['alerts'])->toBe(['DISTRIBUTION_LATE']);
    });

    it('raises VALIDATION_LATE when orders are still sent after the validation cutoff, blocking or not', function (bool $blocking) {
        app(Settings::class)->update(['cutoffs' => ['validation_blocking' => $blocking]], null);
        ($this->at)('2026-10-08 15:30');
        $json = ($this->monitoring)()->assertOk()->json();

        // Centre : deux commandes envoyées ; Est : plus rien à valider ; Ouest : sa répartition reste en retard, sans commande à valider.
        expect(($this->region)($json, $this->centre)['alerts'])->toBe(['VALIDATION_LATE'])
            ->and(($this->region)($json, $this->est)['alerts'])->toBe([])
            ->and(($this->region)($json, $this->ouest)['alerts'])->toBe(['DISTRIBUTION_LATE']);
    })->with([false, true]);

    it('raises both alerts, in that order, and follows the validation cutoff extended for the region (I-71, I-98)', function () {
        ($this->at)('2026-10-08 10:30');
        $this->sentOuest = Order::factory()->create(['business_day_id' => $this->day->id, 'client_id' => Client::factory()->create(['region_id' => $this->ouest->id])->id]);
        app(DayCalendar::class)->extendCutoff($this->centreDay->fresh(), CutoffKind::Validation, '16:00', $this->adv);
        ($this->at)('2026-10-08 15:30');

        $json = ($this->monitoring)()->assertOk()->json();

        // Prolongée à 16:00 : le Centre n'est pas en retard ; l'Ouest l'est (sa répartition et sa validation).
        expect(($this->region)($json, $this->centre)['alerts'])->toBe([])
            ->and(($this->region)($json, $this->centre)['cutoffs']['validation'])->toBe('16:00')
            ->and(($this->region)($json, $this->ouest)['alerts'])->toBe(['DISTRIBUTION_LATE', 'VALIDATION_LATE'])
            ->and(($this->region)($json, $this->est)['cutoffs']['validation'])->toBe('15:00');

        ($this->at)('2026-10-08 16:00');
        expect(($this->region)(($this->monitoring)()->json(), $this->centre)['alerts'])->toBe(['VALIDATION_LATE']);
    });

    it('stops raising alerts once the day is closed: the orders sent have expired', function () {
        ($this->at)('2026-10-08 23:30');
        app(DayCalendar::class)->close($this->day->fresh());

        $json = ($this->monitoring)()->assertOk()->json();

        expect($json['status'])->toBe('CLOSED')
            ->and(array_column($json['regions'], 'alerts'))->toBe([[], [], []])
            ->and($json['totals']['orders']['EXPIRED'])->toBe(2)
            ->and($json['totals']['orders']['SUBMITTED'])->toBe(0)
            ->and(($this->region)($json, $this->centre)['status'])->toBe('CLOSED');
    });
});

describe('cost', function () {
    it('reads a constant number of queries, whatever the number of orders', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->monitoring)()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries(); // Réchauffe les caches (permissions, paramètres).
        $few = $queries();
        foreach (Client::factory()->count(6)->create(['region_id' => $this->centre->id]) as $client) {
            $order = Order::factory()->create(['business_day_id' => $this->day->id, 'client_id' => $client->id]);
            $order->lines()->create(['product_id' => $this->pomme->id, 'requested_qty' => 2, 'unit_price_ht' => '900.00', 'vat_rate' => '19.00']);
            $validated = Order::factory()->validated()->withLines([[$this->orange, 1, 1]])->create(['business_day_id' => $this->day->id, 'client_id' => $client->id]);
            Order::factory()->shipped()->withLines([[$this->pomme, 1, 1]])->create(['business_day_id' => $this->day->id, 'client_id' => $client->id]);
            Order::factory()->rejected()->create(['business_day_id' => $this->day->id, 'client_id' => $client->id]);
            expect($validated->exists)->toBeTrue();
        }

        expect($queries())->toBe($few)
            ->and(($this->region)(($this->monitoring)()->json(), $this->centre)['orders']['SUBMITTED'])->toBe(8)
            ->and(($this->region)(($this->monitoring)()->json(), $this->centre)['clients']['ordered'])->toBe(8);
    });
});
