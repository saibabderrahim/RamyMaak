<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderStatus;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Quotas\QuotaLedger;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Concurrency\ConcurrencyHarness;

uses(ConcurrencyHarness::class);

// Accès simultanés à la validation et au traitement ADV (§5.8 ; contrat du
// lot 6, §1 : I-67 à I-69, I-92), sur MySQL seulement (verrous InnoDB,
// REPEATABLE READ). Mêmes principes que OrdersConcurrencyTest : des processus
// parallèles (pcntl_fork), chacun avec sa connexion, passent par l'API
// (validation, rejet, annulation et statuts ADV, expédition, modification et
// annulation du client) ou par un service (publication des quotas et des
// bornes, clôture). Les arrivées sont imposées par des signaux (verrou pris
// par l'un, l'autre attend) ou groupées par un rendez-vous, puis laissées à
// la course libre.
//
// Propriétés prouvées :
// - V(r,p) ne dépasse jamais Q(r,p) et reste égal à la somme des quantités
//   validées (QuotaLedger::reconcile vide), V(c,p) ne dépasse jamais la borne
//   publiée (QuotaLedger::breaches vide), sous toutes les arrivées ;
// - une écriture concurrente du client, du régional, de l'ADV, de la
//   clôture ou d'une publication est refusée proprement (VERSION_CONFLICT,
//   INVALID_TRANSITION, REGION_QUOTA_EXCEEDED, CLIENT_LIMIT_EXCEEDED,
//   QUOTA_BELOW_VALIDATED), jamais perdue ni doublée ;
// - aucun interblocage : chaque écriture s'exécute en une seule tentative
//   (ConcurrencyHarness::expectClean), même rattrapée par DB::transaction.

beforeEach(function () {
    $this->concurrencySetUp();
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L']);
    $this->citron = Product::factory()->create(['sku' => 'P0071', 'name' => 'Jus citron 1 L']);
    // Titulaire et suppléant du Centre (le suppléant l'est depuis la veille) ; l'ADV, sans affectation, voit tout le périmètre.
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy()->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->adv = userWithRole(Role::Adv);

    // Prise de commande ouverte du Centre le 8 octobre : quotas `[[Product, Q], …]` et bornes `[[Client, Product, B], …]` publiés.
    $this->open = function (array $quotas, array $limits = []): RegionDay {
        $this->regionDay = openOrderingDay('2026-10-08', $this->centre, $quotas, $limits);
        $this->day = $this->regionDay->businessDay;

        return $this->regionDay;
    };
    $this->newClient = fn (): Client => Client::factory()->create(['region_id' => $this->centre->id]);
    $this->newAccount = fn (Client $client): User => userWithRole(Role::Client, ['client_id' => $client->id]);
    $this->lines = fn (array $lines): array => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines);

    // Commande envoyée posée directement : l'envoi contrôle la borne sur le cumul, qui a pu baisser depuis (RG-B09).
    $this->submitted = fn (Client $client, array $lines): Order => Order::factory()->withLines($lines)->create([
        'business_day_id' => $this->day->id, 'client_id' => $client->id, 'region_id' => $client->region_id,
    ]);
    // Commande envoyée par l'API du client (version 1, totaux et historique réels).
    $this->sent = function (User $account, array $lines): Order {
        $id = $this->actingAs($account)->postJson('/api/v1/client/orders', [
            'client_request_id' => (string) Str::uuid(), 'day' => '2026-10-08', 'comment' => null, 'lines' => ($this->lines)($lines),
        ])->assertCreated()->json('id');

        return Order::query()->where('public_id', $id)->sole();
    };
    // Commande validée par le régional au travers d'OrderDecisions (V(r,p) et V(c,p) réels).
    $this->validated = fn (Client $client, array $lines, array $quantities = []): Order => validateOrder(($this->submitted)($client, $lines), $quantities, $this->titular);
    // Statut de traitement atteint dans le processus principal (version à jour lue à chaque commande).
    $this->advance = function (OrderStatus $to, array $orders): array {
        app(AdvOrders::class)->transition($this->adv, $to, array_map(fn (Order $order) => ['id' => $order->public_id, 'version' => $order->refresh()->version], $orders));

        return array_map(fn (Order $order) => $order->refresh(), $orders);
    };

    // Tâches exécutées dans un processus parallèle : la réponse HTTP résumée (ConcurrencyHarness::outcome).
    // La version est lue à la construction, avant le départ des processus.
    $this->validate = function (User $by, Order $order, array $quantities = [], ?int $version = null): Closure {
        $lines = $order->lines()->orderBy('id')->get()->map(function (OrderLine $line) use ($quantities): array {
            $quantity = $quantities[$line->product_id] ?? $line->requested_qty;

            return ['product_id' => $line->product_id, 'validated_qty' => $quantity, 'reason_code' => $quantity < $line->requested_qty ? 'QUOTA_INSUFFISANT' : null];
        })->all();
        $body = ['version' => $version ?? $order->version, 'lines' => $lines];

        return fn (): array => $this->outcome($this->actingAs($by)->postJson("/api/v1/regional/orders/{$order->public_id}/validate", $body));
    };
    $this->reject = function (User $by, Order $order): Closure {
        $body = ['version' => $order->version, 'reason_code' => 'QUOTA_EPUISE', 'comment' => null];

        return fn (): array => $this->outcome($this->actingAs($by)->postJson("/api/v1/regional/orders/{$order->public_id}/reject", $body));
    };
    $this->clientPatch = fn (User $account, Order $order, int $version, array $lines): Closure => fn (): array => $this->outcome(
        $this->actingAs($account)->patchJson("/api/v1/client/orders/{$order->public_id}", [
            'version' => $version, 'comment' => null, 'lines' => ($this->lines)($lines),
        ]),
    );
    $this->clientCancel = fn (User $account, Order $order, int $version): Closure => fn (): array => $this->outcome(
        $this->actingAs($account)->postJson("/api/v1/client/orders/{$order->public_id}/cancel", ['version' => $version]),
    );
    $this->advCancel = function (Order $order): Closure {
        $body = ['version' => $order->refresh()->version, 'reason_code' => 'RUPTURE', 'comment' => null];

        return fn (): array => $this->outcome($this->actingAs($this->adv)->postJson("/api/v1/adv/orders/{$order->public_id}/cancel", $body));
    };
    $this->bulk = function (OrderStatus $to, array $orders): Closure {
        $body = ['status' => $to->value, 'orders' => array_map(fn (Order $order) => ['id' => $order->public_id, 'version' => $order->refresh()->version], $orders)];

        return fn (): array => $this->outcome($this->actingAs($this->adv)->postJson('/api/v1/adv/orders/bulk-status', $body));
    };
    $this->shipping = function (Order $order, array $body): Closure {
        $body = ['version' => $order->refresh()->version, ...$body];

        return fn (): array => $this->outcome($this->actingAs($this->adv)->patchJson("/api/v1/adv/orders/{$order->public_id}/shipping", $body));
    };
    $this->closeDay = fn (): Closure => fn (): array => ['closed' => app(DayCalendar::class)->close($this->day)];
    // Quotas : brouillon enregistré tout de suite, publication dans un processus parallèle.
    $this->draftQuota = fn (Product $product, int $quantity) => app(QuotaBook::class)->saveDrafts($this->day, [
        ['region_id' => $this->centre->id, 'product_id' => $product->id, 'quantity' => $quantity],
    ], $this->adv);
    $this->publishQuotas = fn (): Closure => $this->serviceTask(fn () => app(QuotaBook::class)->publish($this->day, [$this->centre->id], $this->adv)->toArray());
    // Bornes (le régional titulaire) : brouillon d'une cellule, puis envoi de la répartition.
    $this->draftBorne = fn (Client $client, Product $product, ?int $limit, ?RegionDay $regionDay = null, ?User $by = null) => app(LimitBook::class)->saveDrafts(
        $regionDay ?? $this->regionDay,
        [['client_id' => $client->id, 'product_id' => $product->id, 'limit' => $limit]],
        $by ?? $this->titular,
    );
    $this->saveBorne = fn (Client $client, Product $product, ?int $limit, ?RegionDay $regionDay = null, ?User $by = null): Closure => $this->serviceTask(
        fn () => ['changed' => ($this->draftBorne)($client, $product, $limit, $regionDay, $by)],
    );
    $this->publishBornes = fn (): Closure => $this->serviceTask(fn () => app(LimitBook::class)->publish($this->regionDay, $this->titular)->toArray());

    // Compteur V(r,p) du Centre pour le produit.
    $this->counter = fn (Product $product): int => (int) RegionQuota::query()
        ->where('region_day_id', $this->regionDay->id)
        ->where('product_id', $product->id)
        ->value('validated_qty');
    // Cohérence de la journée : V(r,p) = somme des lignes validées (§5.8), V(r,p) ≤ Q(r,p) (I-1), V(c,p) ≤ B(c,p) (I-2).
    $this->consistent = function (): void {
        $day = CarbonImmutable::parse('2026-10-08');
        expect(app(QuotaLedger::class)->reconcile($day, $day))->toBe([])
            ->and(app(QuotaLedger::class)->breaches($day, $day))->toBe([])
            ->and(RegionQuota::query()->whereColumn('validated_qty', '>', 'quantity')->count())->toBe(0);
    };
    // Historique d'une transition d'une commande : [acteur, utilisateur].
    $this->transitions = fn (Order $order, OrderStatus $to): array => OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->where('to_status', $to->value)
        ->orderBy('id')
        ->get()
        ->map(fn (OrderStatusHistory $row) => [$row->actor->value, $row->user_id])
        ->all();
    $this->audited = fn (string $event): array => AuditLog::query()->where('log_name', 'orders')->where('event', $event)->orderBy('id')->pluck('causer_id')->all();
});

afterEach(function () {
    $this->concurrencyTearDown();
});

describe('validations in one region (§5.8, RG-V02)', function () {
    it('validates one of two orders of 40 sent at once on a remaining 40 and refuses the other: V = 40 (§5.8 example)', function (string $arrival) {
        ($this->open)([[$this->orange, 40]]);
        $orders = [($this->submitted)(($this->newClient)(), [[$this->orange, 40]]), ($this->submitted)(($this->newClient)(), [[$this->orange, 40]])];
        // Le titulaire et le suppléant, à la même seconde.
        $authors = [$this->titular, $this->deputy];
        $tasks = [($this->validate)($authors[0], $orders[0]), ($this->validate)($authors[1], $orders[1])];

        $results = $this->parallel($arrival === 'au même point' ? $this->meetAfter($this::LOCK_ORDER, $tasks) : $tasks);

        $this->expectClean($results);
        $won = collect($this->statusesOf($results))->search(200);
        $lost = 1 - $won;
        expect(collect($this->statusesOf($results))->sort()->values()->all())->toBe([200, 409])
            ->and($results[$lost]['value']['code'])->toBe('REGION_QUOTA_EXCEEDED')
            ->and($results[$lost]['value']['body']['violations'])->toHaveCount(1)
            ->and($results[$lost]['value']['body']['violations'][0])->toMatchArray([
                'line' => 0, 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 40, 'quota' => 40, 'validated' => 40,
                'limit' => null, 'client_validated' => 0, 'max_allowed' => 0, 'cause' => 'REGION_QUOTA',
            ])
            ->and($orders[$won]->fresh())->status->toBe(OrderStatus::Validated)->version->toBe(2)->decided_by->toBe($authors[$won]->id)
            ->and($orders[$lost]->fresh())->status->toBe(OrderStatus::Submitted)->version->toBe(1)->decided_by->toBeNull()
            ->and(($this->counter)($this->orange))->toBe(40)
            ->and((int) OrderLine::query()->sum('validated_qty'))->toBe(40)
            // Le journal et l'historique nomment l'auteur réel de la seule validation.
            ->and(($this->audited)('validated'))->toBe([$authors[$won]->id])
            ->and(($this->transitions)($orders[$won], OrderStatus::Validated))->toBe([[OrderActor::Regional->value, $authors[$won]->id]])
            ->and(($this->transitions)($orders[$lost], OrderStatus::Validated))->toBe([]);
        ($this->consistent)();
    })->with(['au même point', 'course libre']);

    it('accepts exactly 40 of 50 validations of 1 on a remaining 40: counter = sum of the lines = 40 (T14)', function (string $arrival) {
        ($this->open)([[$this->orange, 40]]);
        $orders = array_map(fn () => ($this->submitted)(($this->newClient)(), [[$this->orange, 1]]), range(1, 50));
        $tasks = array_map(
            fn (Order $order, int $index) => ($this->validate)($index % 2 === 0 ? $this->titular : $this->deputy, $order),
            $orders,
            array_keys($orders),
        );

        $results = $this->parallel($arrival === 'au même point' ? $this->meetAfter($this::LOCK_ORDER, $tasks) : $tasks);

        $this->expectClean($results);
        $statuses = array_count_values($this->statusesOf($results));
        ksort($statuses);
        $refused = collect($results)->pluck('value')->where('status', 409);
        expect($statuses)->toBe([200 => 40, 409 => 10])
            ->and($refused->pluck('code')->unique()->values()->all())->toBe(['REGION_QUOTA_EXCEEDED'])
            ->and($refused->pluck('body.violations.0.max_allowed')->unique()->values()->all())->toBe([0])
            ->and(($this->counter)($this->orange))->toBe(40)
            ->and((int) OrderLine::query()->sum('validated_qty'))->toBe(40)
            ->and(Order::query()->where('status', OrderStatus::Validated->value)->count())->toBe(40)
            ->and(Order::query()->where('status', OrderStatus::Submitted->value)->count())->toBe(10)
            ->and(($this->audited)('validated'))->toHaveCount(40);
        ($this->consistent)();
    })->with(['au même point', 'course libre']);

    it('never validates more than the quota with orders of several sizes: every refusal is justified (RG-V02, I-69)', function () {
        ($this->open)([[$this->orange, 30]]);
        // 20 commandes de 1 à 5 UV (60 UV demandées) pour 30 UV de quota.
        $orders = array_map(fn (int $index) => ($this->submitted)(($this->newClient)(), [[$this->orange, $index % 5 + 1]]), range(0, 19));
        $tasks = $this->meetAfter($this::LOCK_ORDER, array_map(
            fn (Order $order, int $index) => ($this->validate)($index % 2 === 0 ? $this->titular : $this->deputy, $order),
            $orders,
            array_keys($orders),
        ));

        $results = $this->parallel($tasks);

        $this->expectClean($results);
        $validated = (int) OrderLine::query()->sum('validated_qty');
        expect($validated)->toBeLessThanOrEqual(30)->toBeGreaterThan(24)
            ->and(($this->counter)($this->orange))->toBe($validated)
            ->and(collect($results)->pluck('value.status')->unique()->sort()->values()->all())->toBe([200, 409]);
        // Un refus ne manque jamais de place : demandé > reste au moment du contrôle, et le maximum validable est ce reste.
        foreach (collect($results)->pluck('value')->where('status', 409) as $refusal) {
            $violation = $refusal['body']['violations'][0];
            expect($refusal['code'])->toBe('REGION_QUOTA_EXCEEDED')
                ->and($violation['requested'])->toBeGreaterThan($violation['quota'] - $violation['validated'])
                ->and($violation['max_allowed'])->toBe($violation['quota'] - $violation['validated']);
        }
        ($this->consistent)();
    });

    it('never lets the orders of one client exceed his limit: the second is refused with its maximum (I-2, RG-V03, I-69)', function () {
        $client = ($this->newClient)();
        // Bornes 50 sur l'orange et 30 sur la pomme ; les deux commandes ont été envoyées avant que la borne ne baisse.
        ($this->open)([[$this->orange, 1000], [$this->pomme, 1000]], [[$client, $this->orange, 50], [$client, $this->pomme, 30]]);
        $orders = [
            ($this->submitted)($client, [[$this->orange, 40], [$this->pomme, 25]]),
            ($this->submitted)($client, [[$this->orange, 40], [$this->pomme, 25]]),
        ];

        $results = $this->parallel([($this->validate)($this->titular, $orders[0]), ($this->validate)($this->deputy, $orders[1])]);

        $this->expectClean($results);
        $won = collect($this->statusesOf($results))->search(200);
        $lost = 1 - $won;
        $violations = collect($results[$lost]['value']['body']['violations'])->keyBy('product_id');
        expect(collect($this->statusesOf($results))->sort()->values()->all())->toBe([200, 409])
            ->and($results[$lost]['value']['code'])->toBe('CLIENT_LIMIT_EXCEEDED')
            // Toutes les lignes en cause, avec leur cause et leur maximum (I-69).
            ->and($violations)->toHaveCount(2)
            ->and($violations[$this->orange->id])->toMatchArray([
                'line' => 0, 'requested' => 40, 'quota' => 1000, 'validated' => 40, 'limit' => 50, 'client_validated' => 40, 'max_allowed' => 10, 'cause' => 'CLIENT_LIMIT',
            ])
            ->and($violations[$this->pomme->id])->toMatchArray(['line' => 1, 'requested' => 25, 'limit' => 30, 'client_validated' => 25, 'max_allowed' => 5, 'cause' => 'CLIENT_LIMIT'])
            ->and(($this->counter)($this->orange))->toBe(40)
            ->and(($this->counter)($this->pomme))->toBe(25);
        ($this->consistent)();

        // « Appliquer n » : la même commande, ajustée au maximum, passe et remplit la borne exactement.
        $adjusted = validateOrder($orders[$lost], [$this->orange->id => 10, $this->pomme->id => 5], $this->titular);
        expect($adjusted)->status->toBe(OrderStatus::Validated)->is_adjusted->toBeTrue()
            ->and(($this->counter)($this->orange))->toBe(50)
            ->and(($this->counter)($this->pomme))->toBe(30);
        ($this->consistent)();
    });

    it('validates a request sent twice by the same author once: the second request replays it (I-92)', function () {
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->submitted)(($this->newClient)(), [[$this->orange, 40]]);

        $results = $this->parallel([($this->validate)($this->titular, $order), ($this->validate)($this->titular, $order)]);

        $this->expectClean($results);
        expect($this->statusesOf($results))->toBe([200, 200])
            ->and(collect($results)->pluck('value.body.status')->unique()->values()->all())->toBe(['VALIDATED'])
            ->and(collect($results)->pluck('value.body.version')->unique()->values()->all())->toBe([2])
            ->and($order->fresh())->version->toBe(2)
            ->and(($this->counter)($this->orange))->toBe(40)
            ->and(($this->transitions)($order, OrderStatus::Validated))->toHaveCount(1)
            ->and(($this->audited)('validated'))->toHaveCount(1);
        ($this->consistent)();
    });

    it('lets the titular and the deputy decide the same order once: the second gets INVALID_TRANSITION', function (string $decision) {
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->submitted)(($this->newClient)(), [[$this->orange, 40]]);
        $tasks = match ($decision) {
            'deux validations' => [($this->validate)($this->titular, $order), ($this->validate)($this->deputy, $order)],
            'validation et rejet' => [($this->validate)($this->titular, $order), ($this->reject)($this->deputy, $order)],
        };

        $results = $this->parallel($tasks);

        $this->expectClean($results);
        $won = collect($this->statusesOf($results))->search(200);
        $lost = 1 - $won;
        $order->refresh();
        expect(collect($this->statusesOf($results))->sort()->values()->all())->toBe([200, 409])
            ->and($results[$lost]['value']['code'])->toBe('INVALID_TRANSITION')
            ->and($order->version)->toBe(2)
            ->and($order->decided_by)->toBe([$this->titular, $this->deputy][$won]->id)
            ->and($order->history()->count())->toBe(1);
        // Validée : V pris une fois ; rejetée : aucun compteur touché (I-74).
        if ($order->status === OrderStatus::Validated) {
            expect(($this->counter)($this->orange))->toBe(40)->and(($this->audited)('validated'))->toHaveCount(1);
        } else {
            expect($order->status)->toBe(OrderStatus::Rejected)
                ->and(($this->counter)($this->orange))->toBe(0)
                ->and(($this->audited)('rejected'))->toHaveCount(1)
                ->and(($this->audited)('validated'))->toBe([]);
        }
        ($this->consistent)();
    })->with(['deux validations', 'validation et rejet']);

    it('takes the counters of overlapping products in the same order whatever the order of the lines: no deadlock (I-67)', function () {
        $clients = array_map(fn () => ($this->newClient)(), range(1, 4));
        // Des bornes sur deux des trois produits : des lignes de borne à verrouiller, d'autres absentes.
        ($this->open)(
            [[$this->orange, 1000], [$this->pomme, 1000], [$this->citron, 1000]],
            array_merge(...array_map(fn (Client $client) => [[$client, $this->orange, 500], [$client, $this->citron, 500]], $clients)),
        );
        // Quatre commandes aux trois mêmes produits, lignes créées et envoyées dans des ordres différents.
        $arrangements = [
            [$this->orange, $this->pomme, $this->citron],
            [$this->citron, $this->orange, $this->pomme],
            [$this->pomme, $this->citron, $this->orange],
            [$this->citron, $this->pomme, $this->orange],
        ];
        $orders = array_map(fn (Client $client, array $products) => ($this->submitted)($client, array_map(fn (Product $product) => [$product, 7], $products)), $clients, $arrangements);

        // Tous tiennent leur commande (et ses bornes) avant de prendre les compteurs ensemble.
        $results = $this->parallel($this->meetAfter($this::LOCK_ORDER, array_map(
            fn (Order $order, int $index) => ($this->validate)($index % 2 === 0 ? $this->titular : $this->deputy, $order),
            $orders,
            array_keys($orders),
        )));

        $this->expectClean($results);
        expect($this->statusesOf($results))->toBe([200, 200, 200, 200])
            ->and(($this->counter)($this->orange))->toBe(28)
            ->and(($this->counter)($this->pomme))->toBe(28)
            ->and(($this->counter)($this->citron))->toBe(28);
        ($this->consistent)();
    });
});

describe('validation and decisions against the client writes (R06, §5.8)', function () {
    // Écriture du client et décision du régional sur la même commande : le verrou du client les sérialise (I-67).
    // `$first` : qui prend le verrou du client en premier (l'autre attend sa fin), ou course libre.
    beforeEach(function () {
        $this->raceWithClient = function (string $write, string $decision, string $first): array {
            ($this->open)([[$this->orange, 1000], [$this->pomme, 1000]]);
            $account = ($this->newAccount)(($this->newClient)());
            $order = ($this->sent)($account, [[$this->orange, 40]]);
            $writeTask = match ($write) {
                'modification' => ($this->clientPatch)($account, $order, 1, [[$this->orange, 38], [$this->pomme, 5]]),
                'annulation' => ($this->clientCancel)($account, $order, 1),
            };
            $decisionTask = match ($decision) {
                'validation' => ($this->validate)($this->titular, $order),
                'rejet' => ($this->reject)($this->titular, $order),
            };
            $flag = $this->flag();
            [$writeTask, $decisionTask] = match ($first) {
                'client' => [$this->holding($this::LOCK_CLIENT, $flag, $writeTask), $this->after([$flag], $decisionTask)],
                'régional' => [$this->after([$flag], $writeTask), $this->holding($this::LOCK_CLIENT, $flag, $decisionTask)],
                'course libre' => [$writeTask, $decisionTask],
            };

            $results = $this->parallel([$writeTask, $decisionTask]);

            $this->expectClean($results);

            return [$order->refresh(), $results[0]['value'], $results[1]['value']];
        };
    });

    it('refuses the validation of a stale version after a client modification, and the modification after a validation (R06)', function (string $first) {
        [$order, $write, $decision] = ($this->raceWithClient)('modification', 'validation', $first);

        if ($write['status'] === 200) {
            // Modification d'abord : la validation porte la version périmée, rien n'est validé.
            expect([$decision['status'], $decision['code'], $decision['body']['version'] ?? null])->toBe([409, 'VERSION_CONFLICT', 2])
                ->and($order)->status->toBe(OrderStatus::Submitted)->version->toBe(2)
                ->and($order->lines()->pluck('requested_qty', 'product_id')->all())->toEqual([$this->orange->id => 38, $this->pomme->id => 5])
                ->and(($this->counter)($this->orange))->toBe(0);
        } else {
            // Validation d'abord : la commande validée n'est plus modifiable par le client.
            expect([$write['status'], $write['code']])->toBe([409, 'INVALID_TRANSITION'])
                ->and($decision['status'])->toBe(200)
                ->and($order)->status->toBe(OrderStatus::Validated)->version->toBe(2)
                ->and($order->lines()->pluck('validated_qty', 'product_id')->all())->toEqual([$this->orange->id => 40])
                ->and(($this->counter)($this->orange))->toBe(40);
        }
        match ($first) {
            'client' => expect($write['status'])->toBe(200),
            'régional' => expect($decision['status'])->toBe(200),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['client', 'régional', 'course libre']);

    it('refuses the validation of an order the client cancelled, and the cancellation of a validated order', function (string $first) {
        [$order, $write, $decision] = ($this->raceWithClient)('annulation', 'validation', $first);

        if ($write['status'] === 200) {
            expect([$decision['status'], $decision['code']])->toBe([409, 'INVALID_TRANSITION'])
                ->and($order)->status->toBe(OrderStatus::Cancelled)->version->toBe(2)
                ->and(($this->counter)($this->orange))->toBe(0);
        } else {
            expect([$write['status'], $write['code']])->toBe([409, 'INVALID_TRANSITION'])
                ->and($decision['status'])->toBe(200)
                ->and($order)->status->toBe(OrderStatus::Validated)->version->toBe(2)
                ->and(($this->counter)($this->orange))->toBe(40);
        }
        match ($first) {
            'client' => expect($write['status'])->toBe(200),
            'régional' => expect($decision['status'])->toBe(200),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['client', 'régional', 'course libre']);

    it('refuses the rejection of a stale version after a client modification, and the modification after a rejection', function (string $first) {
        [$order, $write, $decision] = ($this->raceWithClient)('modification', 'rejet', $first);

        if ($write['status'] === 200) {
            expect([$decision['status'], $decision['code'], $decision['body']['version'] ?? null])->toBe([409, 'VERSION_CONFLICT', 2])
                ->and($order)->status->toBe(OrderStatus::Submitted)->version->toBe(2)
                ->and($order->lines()->pluck('requested_qty', 'product_id')->all())->toEqual([$this->orange->id => 38, $this->pomme->id => 5]);
        } else {
            expect([$write['status'], $write['code']])->toBe([409, 'INVALID_TRANSITION'])
                ->and($decision['status'])->toBe(200)
                ->and($order)->status->toBe(OrderStatus::Rejected)->version->toBe(2)
                ->and($order->decision_reason_code)->toBe('QUOTA_EPUISE')
                ->and($order->lines()->pluck('requested_qty', 'product_id')->all())->toEqual([$this->orange->id => 40]);
        }
        // Ni rejet ni modification ne touchent le compteur.
        expect(($this->counter)($this->orange))->toBe(0);
        match ($first) {
            'client' => expect($write['status'])->toBe(200),
            'régional' => expect($decision['status'])->toBe(200),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['client', 'régional', 'course libre']);
});

describe('validation and closing of the day (§4.4, I-67)', function () {
    it('always closes the day when a validation and a rejection race the closing: no deadlock, no order left sent', function (string $first) {
        ($this->open)([[$this->orange, 100]]);
        [$validated, $rejected, $pending] = array_map(fn () => ($this->submitted)(($this->newClient)(), [[$this->orange, 40]]), range(1, 3));
        // « écritures » : la clôture part quand les deux décisions tiennent la journée en partagé ; « clôture » : l'inverse.
        $flags = [$this->flag(), $this->flag()];
        $closing = $this->flag();
        $writer = fn (Closure $write, string $flag) => match ($first) {
            'écritures' => $this->holding($this::LOCK_DAY_SHARED, $flag, $write),
            'clôture' => $this->after([$closing], $write),
            'course libre' => $write,
        };
        $close = match ($first) {
            'écritures' => $this->after($flags, ($this->closeDay)()),
            'clôture' => $this->holding($this::LOCK_DAY_EXCLUSIVE, $closing, ($this->closeDay)()),
            'course libre' => ($this->closeDay)(),
        };

        $results = $this->parallel([
            $writer(($this->validate)($this->titular, $validated), $flags[0]),
            $writer(($this->reject)($this->deputy, $rejected), $flags[1]),
            $close,
        ]);

        $this->expectClean($results);
        [$validation, $rejection] = [$results[0]['value'], $results[1]['value']];
        expect($results[2]['value'])->toBe(['closed' => true])
            ->and($this->day->fresh()->isOpen())->toBeFalse()
            ->and(Order::query()->where('status', OrderStatus::Submitted->value)->count())->toBe(0)
            ->and($pending->fresh()->status)->toBe(OrderStatus::Expired);

        // Décision prise avant la clôture : la commande n'est pas expirée ; sinon refusée, la commande expire.
        if ($validation['status'] === 200) {
            expect($validated->fresh())->status->toBe(OrderStatus::Validated)->version->toBe(2)
                ->and(($this->counter)($this->orange))->toBe(40);
        } else {
            expect($validation['code'])->toBeIn(['INVALID_TRANSITION', 'DAY_NOT_OPEN'])
                ->and($validated->fresh())->status->toBe(OrderStatus::Expired)
                ->and(($this->counter)($this->orange))->toBe(0);
        }
        if ($rejection['status'] === 200) {
            expect($rejected->fresh())->status->toBe(OrderStatus::Rejected)->version->toBe(2);
        } else {
            expect($rejection['code'])->toBeIn(['INVALID_TRANSITION', 'DAY_NOT_OPEN'])
                ->and($rejected->fresh())->status->toBe(OrderStatus::Expired);
        }
        match ($first) {
            'écritures' => expect([$validation['status'], $rejection['status']])->toBe([200, 200]),
            'clôture' => expect([$validation['code'], $rejection['code']])->toBe(['INVALID_TRANSITION', 'INVALID_TRANSITION']),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['écritures', 'clôture', 'course libre']);
});

describe('validation and publications (T06, T07, RG-Q05, RG-B09)', function () {
    it('waits for a validation before lowering the quota to the validated quantity, and refuses lower (T07, R09)', function (string $first, int $draft) {
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->submitted)(($this->newClient)(), [[$this->orange, 40]]);
        ($this->draftQuota)($this->orange, $draft);
        $flag = $this->flag();
        $validate = ($this->validate)($this->titular, $order);
        $publish = ($this->publishQuotas)();
        [$validate, $publish] = match ($first) {
            'validation' => [$this->holding($this::LOCK_DAY_SHARED, $flag, $validate), $this->after([$flag], $publish)],
            'publication' => [$this->after([$flag], $validate), $this->holding($this::LOCK_DAY_EXCLUSIVE, $flag, $publish)],
            'course libre' => [$validate, $publish],
        };

        $results = $this->parallel([$validate, $publish]);

        $this->expectClean($results);
        [$validation, $publication] = [$results[0]['value'], $results[1]['value']];
        $quota = RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $this->orange->id)->sole();
        if ($validation['status'] === 200) {
            // Validation d'abord : la publication voit V = 40, accepte Q = 40 exactement et refuse 39.
            expect($quota->validated_qty)->toBe(40);
            if ($draft === 40) {
                expect($publication['status'])->toBe(200)->and($quota->quantity)->toBe(40)->and($quota->draft_quantity)->toBeNull();
            } else {
                expect([$publication['status'], $publication['code']])->toBe([409, 'QUOTA_BELOW_VALIDATED'])
                    ->and($publication['body']['violations'][0])->toMatchArray(['product_id' => $this->orange->id, 'validated' => 40, 'requested' => 39])
                    ->and($quota->quantity)->toBe(100)
                    ->and($quota->draft_quantity)->toBe(39);
            }
        } else {
            // Publication d'abord (Q = 39 seulement) : la validation de 40 dépasse le nouveau quota.
            expect($draft)->toBe(39)
                ->and($publication['status'])->toBe(200)
                ->and([$validation['status'], $validation['code']])->toBe([409, 'REGION_QUOTA_EXCEEDED'])
                ->and($validation['body']['violations'][0])->toMatchArray(['quota' => 39, 'validated' => 0, 'requested' => 40, 'max_allowed' => 39, 'cause' => 'REGION_QUOTA'])
                ->and($quota->quantity)->toBe(39)
                ->and($quota->validated_qty)->toBe(0);
        }
        match ([$first, $draft]) {
            ['validation', 40], ['validation', 39] => expect($validation['status'])->toBe(200),
            ['publication', 40] => expect([$validation['status'], $publication['status']])->toBe([200, 200]),
            ['publication', 39] => expect($validation['status'])->toBe(409),
            ['course libre', 40] => expect([$validation['status'], $publication['status']])->toBe([200, 200]),
            default => null,
        };
        ($this->consistent)();
    })->with([
        'validation, quota ramené à V' => ['validation', 40],
        'validation, quota sous V' => ['validation', 39],
        'publication, quota ramené à V' => ['publication', 40],
        'publication, quota sous V' => ['publication', 39],
        'course libre, quota ramené à V' => ['course libre', 40],
        'course libre, quota sous V' => ['course libre', 39],
    ]);
});

describe('validation and bornes (RG-B09, I-2, I-67)', function () {
    it('holds a border draft until the validation is over: refused below the new V, no deadlock on a client without border', function (string $first) {
        $client = ($this->newClient)();
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->submitted)($client, [[$this->orange, 30]]);
        $flag = $this->flag();
        $validate = ($this->validate)($this->deputy, $order);
        $save = ($this->saveBorne)($client, $this->orange, 20);
        // Brouillon d'abord : il tient le client en partagé puis la journée régionale en exclusif ; la validation du même client attend le client.
        [$validate, $save] = match ($first) {
            'validation' => [$this->holding($this::LOCK_REGION_DAY_SHARED, $flag, $validate), $this->after([$flag], $save)],
            'brouillon' => [$this->after([$flag], $validate), $this->holding($this::LOCK_REGION_DAY_EXCLUSIVE, $flag, $save)],
            'course libre' => [$validate, $save],
        };

        $results = $this->parallel([$validate, $save]);

        $this->expectClean($results);
        [$validation, $draft] = [$results[0]['value'], $results[1]['value']];
        $limit = ClientLimit::query()->where('client_id', $client->id)->where('product_id', $this->orange->id)->first();
        // Un brouillon n'est jamais une borne publiée : la validation passe dans tous les cas.
        expect($validation['status'])->toBe(200)
            ->and(($this->counter)($this->orange))->toBe(30);
        if ($draft['status'] === 200) {
            // Brouillon d'abord : enregistré (V = 0 alors) ; son envoi sera refusé sous V = 30.
            expect($limit)->has_draft->toBeTrue()->draft_max_qty->toBe(20)->max_qty->toBeNull();
            try {
                app(LimitBook::class)->publish($this->regionDay, $this->titular);
                $this->fail('L’envoi d’une borne sous V aurait dû être refusé.');
            } catch (ApiException $e) {
                expect($e->errorCode)->toBe(ErrorCode::QuotaBelowValidated)
                    ->and($e->extra['violations'][0])->toMatchArray(['product_id' => $this->orange->id, 'requested' => 20, 'validated' => 30]);
            }
        } else {
            // Validation d'abord : le brouillon sous V = 30 est refusé, rien n'est écrit.
            expect($draft['status'])->toBe(422)
                ->and($draft['body']['errors'])->toHaveKey('cells.0.limit')
                ->and($limit)->toBeNull();
        }
        match ($first) {
            'validation' => expect($draft['status'])->toBe(422),
            'brouillon' => expect($draft['status'])->toBe(200),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['validation', 'brouillon', 'course libre']);

    it('holds the publication of a border below the validated quantity until the validation is over, and the validation until a lower border is published (I-2)', function (string $first) {
        $client = ($this->newClient)();
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->submitted)($client, [[$this->orange, 30]]);
        // Borne de 20 en brouillon, V = 0 : enregistrée sans obstacle ; la validation de 30 la rendra impubliable.
        ($this->draftBorne)($client, $this->orange, 20);
        $flag = $this->flag();
        $validate = ($this->validate)($this->deputy, $order);
        $publish = ($this->publishBornes)();
        [$validate, $publish] = match ($first) {
            'validation' => [$this->holding($this::LOCK_REGION_DAY_SHARED, $flag, $validate), $this->after([$flag], $publish)],
            'publication' => [$this->after([$flag], $validate), $this->holding($this::LOCK_REGION_DAY_EXCLUSIVE, $flag, $publish)],
            'course libre' => [$validate, $publish],
        };

        $results = $this->parallel([$validate, $publish]);

        $this->expectClean($results);
        [$validation, $publication] = [$results[0]['value'], $results[1]['value']];
        $limit = ClientLimit::query()->where('client_id', $client->id)->where('product_id', $this->orange->id)->sole();
        if ($validation['status'] === 200) {
            // Validation d'abord : l'envoi attend, voit V = 30 et refuse la borne de 20 ; le brouillon reste.
            expect(($this->counter)($this->orange))->toBe(30)
                ->and([$publication['status'], $publication['code']])->toBe([409, 'QUOTA_BELOW_VALIDATED'])
                ->and($publication['body']['violations'][0])->toMatchArray(['product_id' => $this->orange->id, 'requested' => 20, 'validated' => 30])
                ->and($limit)->has_draft->toBeTrue()->max_qty->toBeNull();
        } else {
            // Envoi d'abord : la borne de 20 est publiée, la validation de 30 la dépasse (maximum 20).
            expect($publication['status'])->toBe(200)
                ->and([$validation['status'], $validation['code']])->toBe([409, 'CLIENT_LIMIT_EXCEEDED'])
                ->and($validation['body']['violations'][0])->toMatchArray(['product_id' => $this->orange->id, 'limit' => 20, 'client_validated' => 0, 'max_allowed' => 20, 'cause' => 'CLIENT_LIMIT'])
                ->and($limit)->has_draft->toBeFalse()->max_qty->toBe(20)
                ->and($order->fresh()->status)->toBe(OrderStatus::Submitted)
                ->and(($this->counter)($this->orange))->toBe(0);
        }
        match ($first) {
            'validation' => expect($validation['status'])->toBe(200),
            'publication' => expect($publication['status'])->toBe(200),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['validation', 'publication', 'course libre']);

    it('lets a border draft for one client and the validation of another go through in either order: no gap-lock deadlock on an absent border', function (string $first) {
        [$drafted, $validated] = [($this->newClient)(), ($this->newClient)()];
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->submitted)($validated, [[$this->orange, 30]]);
        $flag = $this->flag();
        $validate = ($this->validate)($this->deputy, $order);
        $save = ($this->saveBorne)($drafted, $this->orange, 20);
        [$validate, $save] = match ($first) {
            'validation' => [$this->holding($this::LOCK_BORNE, $flag, $validate), $this->after([$flag], $save)],
            'brouillon' => [$this->after([$flag], $validate), $this->holding($this::LOCK_REGION_DAY_EXCLUSIVE, $flag, $save)],
            'course libre' => [$validate, $save],
        };

        $results = $this->parallel([$validate, $save]);

        $this->expectClean($results);
        expect($this->statusesOf($results))->toBe([200, 200])
            ->and(($this->counter)($this->orange))->toBe(30)
            ->and(ClientLimit::query()->where('client_id', $drafted->id)->where('product_id', $this->orange->id)->sole())->has_draft->toBeTrue()->draft_max_qty->toBe(20);
        ($this->consistent)();
    })->with(['validation', 'brouillon', 'course libre']);

    it('does not block a border draft of another region on the gap lock of a validation without border (lot 4 gap locks)', function () {
        // Centre : validation d'un client sans borne (verrou d'intervalle sur l'index des bornes, à la place de sa borne absente).
        $absent = ($this->newClient)();
        $est = Region::factory()->create(['code' => 'EST']);
        // Est : d'abord le client qui recevra une borne (identifiant le plus bas), puis un client déjà borné (successeur du verrou d'intervalle).
        [$target, $anchor] = [Client::factory()->create(['region_id' => $est->id]), Client::factory()->create(['region_id' => $est->id])];
        ($this->open)([[$this->orange, 100]]);
        $estDay = openOrderingDay('2026-10-08', $est, [[$this->orange, 100]], [[$anchor, $this->orange, 10]]);
        $estRegional = RegionAssignment::query()->where('region_id', $est->id)->sole()->user;
        $order = ($this->submitted)($absent, [[$this->orange, 30]]);
        $flag = $this->flag();

        $results = $this->parallel([
            $this->holding($this::LOCK_BORNE, $flag, ($this->validate)($this->titular, $order)),
            $this->after([$flag], ($this->saveBorne)($target, $this->orange, 5, $estDay, $estRegional)),
        ]);

        $this->expectClean($results);
        expect($this->statusesOf($results))->toBe([200, 200])
            ->and(($this->counter)($this->orange))->toBe(30)
            ->and(ClientLimit::query()->where('region_day_id', $estDay->id)->where('client_id', $target->id)->sole())->draft_max_qty->toBe(5);
        ($this->consistent)();
    });
});

describe('ADV writers (RG-A02, RG-V07, I-76, I-78)', function () {
    it('lets one of a cancellation and a status change of the same order win, the other refused', function (string $first) {
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->validated)(($this->newClient)(), [[$this->orange, 40]]);
        expect(($this->counter)($this->orange))->toBe(40);
        $flag = $this->flag();
        $cancel = ($this->advCancel)($order);
        $bulk = ($this->bulk)(OrderStatus::InPreparation, [$order]);
        [$cancel, $bulk] = match ($first) {
            'annulation' => [$this->holding($this::LOCK_ORDER, $flag, $cancel), $this->after([$flag], $bulk)],
            'statut' => [$this->after([$flag], $cancel), $this->holding($this::LOCK_ORDER, $flag, $bulk)],
            'course libre' => [$cancel, $bulk],
        };

        $results = $this->parallel([$cancel, $bulk]);

        $this->expectClean($results);
        [$cancellation, $change] = [$results[0]['value'], $results[1]['value']];
        $order->refresh();
        expect($change['status'])->toBe(200);
        if ($cancellation['status'] === 200) {
            // Annulation d'abord : l'élément du lot est refusé, V restitué.
            expect($change['body']['applied'])->toBe([])
                ->and($change['body']['refused'][0])->toMatchArray(['id' => $order->public_id, 'code' => 'INVALID_TRANSITION', 'version' => 3])
                ->and($order)->status->toBe(OrderStatus::Cancelled)->version->toBe(3)
                ->and(($this->counter)($this->orange))->toBe(0)
                ->and(($this->transitions)($order, OrderStatus::Cancelled))->toBe([[OrderActor::Adv->value, $this->adv->id]])
                ->and(($this->transitions)($order, OrderStatus::InPreparation))->toBe([]);
        } else {
            // Statut d'abord : l'annulation porte la version périmée, V reste pris.
            expect([$cancellation['status'], $cancellation['code'], $cancellation['body']['version'] ?? null])->toBe([409, 'VERSION_CONFLICT', 3])
                ->and($change['body']['refused'])->toBe([])
                ->and($change['body']['applied'][0])->toMatchArray(['id' => $order->public_id, 'status' => 'IN_PREPARATION', 'version' => 3])
                ->and($order)->status->toBe(OrderStatus::InPreparation)->version->toBe(3)
                ->and(($this->counter)($this->orange))->toBe(40)
                ->and(($this->transitions)($order, OrderStatus::Cancelled))->toBe([]);
        }
        match ($first) {
            'annulation' => expect($cancellation['status'])->toBe(200),
            'statut' => expect($change['body']['applied'])->toHaveCount(1),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['annulation', 'statut', 'course libre']);

    it('releases once the counter of a cancellation sent twice by the same author (I-92)', function () {
        ($this->open)([[$this->orange, 100]]);
        $order = ($this->validated)(($this->newClient)(), [[$this->orange, 40]]);
        $cancel = ($this->advCancel)($order);

        $results = $this->parallel([$cancel, ($this->advCancel)($order)]);

        $this->expectClean($results);
        expect($this->statusesOf($results))->toBe([200, 200])
            ->and(collect($results)->pluck('value.body.status')->unique()->values()->all())->toBe(['CANCELLED'])
            ->and($order->fresh())->status->toBe(OrderStatus::Cancelled)->version->toBe(3)
            ->and(($this->counter)($this->orange))->toBe(0)
            ->and(($this->transitions)($order, OrderStatus::Cancelled))->toHaveCount(1)
            ->and(($this->audited)('cancelled_by_adv'))->toHaveCount(1);
        ($this->consistent)();
    });

    it('never goes above the quota when a cancellation frees the last units while another order is validated on them (T08, RG-V02)', function (string $first) {
        ($this->open)([[$this->orange, 40], [$this->pomme, 40], [$this->citron, 40]]);
        $products = $first === 'annulation' || $first === 'validation' ? [$this->orange] : [$this->orange, $this->pomme, $this->citron];

        foreach ($products as $product) {
            // A tient les 40 UV du produit (V = Q) ; B en demande autant.
            $holder = ($this->validated)(($this->newClient)(), [[$product, 40]]);
            $waiting = ($this->submitted)(($this->newClient)(), [[$product, 40]]);
            expect(($this->counter)($product))->toBe(40);
            $flag = $this->flag();
            $cancel = ($this->advCancel)($holder);
            $validate = ($this->validate)($this->titular, $waiting);
            // « annulation » : l'annulation garde le compteur, la validation attend ; « validation » : l'inverse.
            [$cancel, $validate] = match ($first) {
                'annulation' => [$this->holding($this::UPDATE_COUNTER, $flag, $cancel), $this->after([$flag], $validate)],
                'validation' => [$this->after([$flag], $cancel), $this->holding($this::LOCK_COUNTER, $flag, $validate)],
                'au même point' => $this->meetAfter($this::LOCK_ORDER, [$cancel, $validate]),
                'course libre' => [$cancel, $validate],
            };

            $results = $this->parallel([$cancel, $validate]);

            $this->expectClean($results);
            [$cancellation, $validation] = [$results[0]['value'], $results[1]['value']];
            expect($cancellation['status'])->toBe(200)
                ->and($holder->fresh()->status)->toBe(OrderStatus::Cancelled);
            if ($validation['status'] === 200) {
                // Les unités libérées sont reprises : V = 40, jamais plus.
                expect($waiting->fresh()->status)->toBe(OrderStatus::Validated)->and(($this->counter)($product))->toBe(40);
            } else {
                // La validation a vu V = 40 avant la restitution : refusée, rien de pris.
                expect([$validation['status'], $validation['code']])->toBe([409, 'REGION_QUOTA_EXCEEDED'])
                    ->and($validation['body']['violations'][0])->toMatchArray(['quota' => 40, 'validated' => 40, 'max_allowed' => 0])
                    ->and($waiting->fresh()->status)->toBe(OrderStatus::Submitted)
                    ->and(($this->counter)($product))->toBe(0);
            }
            match ($first) {
                'annulation' => expect($validation['status'])->toBe(200),
                'validation' => expect($validation['status'])->toBe(409),
                default => null,
            };
            ($this->consistent)();
        }
    })->with(['annulation', 'validation', 'au même point', 'course libre']);

    it('takes the orders of two overlapping bulk status changes in the same order: no deadlock, every order moved once (I-67, I-78)', function (OrderStatus $to, string $selection) {
        ($this->open)([[$this->orange, 1000]]);
        $orders = array_map(fn () => ($this->validated)(($this->newClient)(), [[$this->orange, 10]]), range(1, 4));
        if ($to === OrderStatus::Prepared) {
            $orders = ($this->advance)(OrderStatus::InPreparation, $orders);
        }
        [$one, $two, $three, $four] = $orders;
        // Sélections inversées, ou qui se recouvrent en partie et en sens contraire.
        [$first, $second] = match ($selection) {
            'sélections inversées' => [[$one, $two, $three, $four], [$four, $three, $two, $one]],
            'recouvrement partiel' => [[$one, $two, $three], [$four, $three, $two]],
        };

        // Les deux appels tiennent la journée en partagé avant de se disputer les commandes.
        $results = $this->parallel($this->meetAfter($this::LOCK_DAY_SHARED, [($this->bulk)($to, $first), ($this->bulk)($to, $second)]));

        $this->expectClean($results);
        expect($this->statusesOf($results))->toBe([200, 200]);
        // Chaque commande passe une fois : la seconde demande, version suivante, est un renvoi appliqué (I-92).
        foreach ([[$first, $results[0]], [$second, $results[1]]] as [$selected, $result]) {
            expect($result['value']['body']['refused'])->toBe([])
                ->and(collect($result['value']['body']['applied'])->pluck('id')->all())->toBe(array_map(fn (Order $order) => $order->public_id, $selected))
                ->and(collect($result['value']['body']['applied'])->pluck('status')->unique()->values()->all())->toBe([$to->value]);
        }
        foreach ($orders as $order) {
            $order->refresh();
            expect($order->status)->toBe($to)
                ->and($order->version)->toBe($to === OrderStatus::Prepared ? 4 : 3)
                ->and(($this->transitions)($order, $to))->toBe([[OrderActor::Adv->value, $this->adv->id]]);
            if ($to === OrderStatus::Prepared) {
                // Quantité préparée = quantité validée par défaut (I-77).
                expect($order->lines()->pluck('shipped_qty')->all())->toBe([10]);
            }
        }
        expect(($this->counter)($this->orange))->toBe(40);
        ($this->consistent)();
    })->with(function () {
        foreach ([OrderStatus::InPreparation, OrderStatus::Prepared] as $to) {
            foreach (['sélections inversées', 'recouvrement partiel'] as $selection) {
                yield "{$to->value}, {$selection}" => [$to, $selection];
            }
        }
    });

    it('moves the orders to preparation whether the day closes before or after: the closing expires only the sent orders (§4.4)', function (string $first) {
        ($this->open)([[$this->orange, 1000]]);
        $moved = [($this->validated)(($this->newClient)(), [[$this->orange, 10]]), ($this->validated)(($this->newClient)(), [[$this->orange, 20]])];
        $pending = ($this->submitted)(($this->newClient)(), [[$this->orange, 5]]);
        $flag = $this->flag();
        $bulk = ($this->bulk)(OrderStatus::InPreparation, $moved);
        $close = ($this->closeDay)();
        [$bulk, $close] = match ($first) {
            'statuts' => [$this->holding($this::LOCK_DAY_SHARED, $flag, $bulk), $this->after([$flag], $close)],
            'clôture' => [$this->after([$flag], $bulk), $this->holding($this::LOCK_DAY_EXCLUSIVE, $flag, $close)],
            'course libre' => [$bulk, $close],
        };

        $results = $this->parallel([$bulk, $close]);

        $this->expectClean($results);
        expect($results[1]['value'])->toBe(['closed' => true])
            ->and($results[0]['value']['status'])->toBe(200)
            // Le traitement continue après la clôture : tout est appliqué.
            ->and($results[0]['value']['body']['refused'])->toBe([])
            ->and($results[0]['value']['body']['applied'])->toHaveCount(2)
            ->and($this->day->fresh()->isOpen())->toBeFalse()
            ->and($pending->fresh())->status->toBe(OrderStatus::Expired)
            ->and($moved[0]->fresh())->status->toBe(OrderStatus::InPreparation)->version->toBe(3)
            ->and($moved[1]->fresh())->status->toBe(OrderStatus::InPreparation)->version->toBe(3)
            ->and(($this->counter)($this->orange))->toBe(30);
        ($this->consistent)();
    })->with(['statuts', 'clôture', 'course libre']);

    it('releases the counter of a cancellation whether the day closes before or after (H-06, I-76)', function (string $first) {
        ($this->open)([[$this->orange, 1000]]);
        $order = ($this->validated)(($this->newClient)(), [[$this->orange, 40]]);
        $pending = ($this->submitted)(($this->newClient)(), [[$this->orange, 5]]);
        $flag = $this->flag();
        $cancel = ($this->advCancel)($order);
        $close = ($this->closeDay)();
        [$cancel, $close] = match ($first) {
            'annulation' => [$this->holding($this::LOCK_DAY_SHARED, $flag, $cancel), $this->after([$flag], $close)],
            'clôture' => [$this->after([$flag], $cancel), $this->holding($this::LOCK_DAY_EXCLUSIVE, $flag, $close)],
            'course libre' => [$cancel, $close],
        };

        $results = $this->parallel([$cancel, $close]);

        $this->expectClean($results);
        expect($results[1]['value'])->toBe(['closed' => true])
            ->and($results[0]['value']['status'])->toBe(200)
            ->and($order->fresh())->status->toBe(OrderStatus::Cancelled)->version->toBe(3)
            ->and($pending->fresh()->status)->toBe(OrderStatus::Expired)
            // V est restitué même sur une journée clôturée.
            ->and(($this->counter)($this->orange))->toBe(0);
        ($this->consistent)();
    })->with(['annulation', 'clôture', 'course libre']);

    it('lets one of a shipping update and a status change of the same order win, the other refused (RG-A03, I-79)', function (string $first) {
        ($this->open)([[$this->orange, 100], [$this->pomme, 100]]);
        $order = ($this->validated)(($this->newClient)(), [[$this->orange, 40], [$this->pomme, 10]]);
        $order = ($this->advance)(OrderStatus::InPreparation, [$order])[0];
        expect($order->version)->toBe(3);
        $flag = $this->flag();
        $ship = ($this->shipping)($order, [
            'delivery_note_ref' => 'BL-0042',
            'lines' => [['product_id' => $this->orange->id, 'shipped_qty' => 35, 'reason_code' => 'RUPTURE']],
        ]);
        $bulk = ($this->bulk)(OrderStatus::Prepared, [$order]);
        [$ship, $bulk] = match ($first) {
            'expédition' => [$this->holding($this::LOCK_ORDER, $flag, $ship), $this->after([$flag], $bulk)],
            'statut' => [$this->after([$flag], $ship), $this->holding($this::LOCK_ORDER, $flag, $bulk)],
            'course libre' => [$ship, $bulk],
        };

        $results = $this->parallel([$ship, $bulk]);

        $this->expectClean($results);
        [$shipment, $change] = [$results[0]['value'], $results[1]['value']];
        $order->refresh();
        $shipped = $order->lines()->pluck('shipped_qty', 'product_id')->all();
        expect($change['status'])->toBe(200)->and($order->version)->toBe(4);
        if ($shipment['status'] === 200) {
            // Expédition d'abord : le lot porte la version périmée, la commande reste en préparation.
            expect($change['body']['applied'])->toBe([])
                ->and($change['body']['refused'][0])->toMatchArray(['code' => 'VERSION_CONFLICT', 'version' => 4])
                ->and($order)->status->toBe(OrderStatus::InPreparation)->delivery_note_ref->toBe('BL-0042')
                ->and($shipped)->toEqual([$this->orange->id => 35, $this->pomme->id => null]);
        } else {
            // Statut d'abord : la commande est préparée (expédié = validé), l'expédition porte la version périmée.
            expect([$shipment['status'], $shipment['code'], $shipment['body']['version'] ?? null])->toBe([409, 'VERSION_CONFLICT', 4])
                ->and($change['body']['applied'])->toHaveCount(1)
                ->and($order)->status->toBe(OrderStatus::Prepared)->delivery_note_ref->toBeNull()
                ->and($shipped)->toEqual([$this->orange->id => 40, $this->pomme->id => 10]);
        }
        // Le quota n'est jamais touché par l'expédition (RG-A03, Q-16).
        expect(($this->counter)($this->orange))->toBe(40)->and(($this->counter)($this->pomme))->toBe(10);
        match ($first) {
            'expédition' => expect($shipment['status'])->toBe(200),
            'statut' => expect($change['body']['applied'])->toHaveCount(1),
            'course libre' => null,
        };
        ($this->consistent)();
    })->with(['expédition', 'statut', 'course libre']);
});
