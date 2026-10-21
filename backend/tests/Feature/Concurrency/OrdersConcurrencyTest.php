<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderUsage;
use App\Domain\Settings\Settings;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Concurrency\ConcurrencyHarness;

uses(ConcurrencyHarness::class);

// Accès simultanés aux commandes (§5.8 ; contrat du lot 5, I-61), sur MySQL
// seulement (verrous InnoDB, REPEATABLE READ) : des processus parallèles
// (pcntl_fork), chacun avec sa propre connexion, partent au même signal et
// passent par l'API (POST, PATCH, annulation) ou par DayCalendar::close.
// L'outillage (processus, signaux, rendez-vous, nettoyage) est celui de
// ConcurrencyHarness, commun aux tests du lot 6.
//
// Propriétés prouvées :
// - un même client est sérialisé (verrou du client) : RG-C04, RG-C03 sur le
//   cumul et RG-C07 tiennent sous la concurrence ;
// - des clients différents ne s'interbloquent pas (numéro du jour alloué en
//   dernier, en une instruction) : tous réussissent, numéros consécutifs ;
// - modification, annulation et clôture suivent le même ordre de verrous
//   (client → journée → commande ; journée → commandes) : la clôture réussit
//   toujours et ne laisse aucune commande envoyée.
// Chaque transaction s'exécute en une seule tentative : aucun interblocage,
// même rattrapé par les tentatives de ClientOrders, n'est toléré.
//
// Les données sont validées (COMMIT) pour être vues des autres connexions,
// puis effacées après chaque test (TRUNCATE de la base de test, sauf les
// rôles, permissions et motifs semés par les migrations).

beforeEach(function () {
    $this->concurrencySetUp();
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L']);
    // Client du Centre avec une borne de 50 sur l'orange (RG-C03 au cumul).
    $this->bounded = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->open = function (string $date): BusinessDay {
        openOrderingDay($date, $this->centre, [[$this->orange, 1000], [$this->pomme, 1000]], [[$this->bounded, $this->orange, 50]]);

        return openOrderingDay($date, $this->est, [[$this->orange, 1000], [$this->pomme, 1000]])->businessDay;
    };
    $this->day = ($this->open)('2026-10-08');
    $this->settings = fn (array $orders) => app(Settings::class)->update(['orders' => $orders], null);

    // Comptes : `$count` comptes d'un même client (plusieurs appareils, Q-11).
    $this->accounts = fn (Client $client, int $count): array => array_map(
        fn () => userWithRole(Role::Client, ['client_id' => $client->id]),
        range(1, $count),
    );
    // Un compte pour chacun de `$count` nouveaux clients de la région.
    $this->clients = fn (int $count, Region $region): array => array_map(
        fn () => userWithRole(Role::Client, ['client_id' => Client::factory()->create(['region_id' => $region->id])->id]),
        range(1, $count),
    );
    $this->lines = fn (array $lines): array => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines);

    // Envoi préparé dans le processus principal (requête du client).
    $this->sent = function (User $account, array $lines, string $date = '2026-10-08'): Order {
        $id = $this->actingAs($account)->postJson('/api/v1/client/orders', [
            'client_request_id' => (string) Str::uuid(), 'day' => $date, 'comment' => null, 'lines' => ($this->lines)($lines),
        ])->assertCreated()->json('id');

        return Order::query()->where('public_id', $id)->sole();
    };

    // Tâches exécutées dans un processus parallèle : la réponse HTTP résumée (ConcurrencyHarness::outcome).
    $this->post = function (User $account, array $lines, ?string $requestId = null, string $date = '2026-10-08'): Closure {
        $body = ['client_request_id' => $requestId ?? (string) Str::uuid(), 'day' => $date, 'comment' => null, 'lines' => ($this->lines)($lines)];

        return fn (): array => $this->outcome($this->actingAs($account)->postJson('/api/v1/client/orders', $body));
    };
    $this->patch = fn (User $account, Order $order, int $version, array $lines): Closure => fn (): array => $this->outcome(
        $this->actingAs($account)->patchJson("/api/v1/client/orders/{$order->public_id}", [
            'version' => $version, 'comment' => null, 'lines' => ($this->lines)($lines),
        ]),
    );
    $this->cancel = fn (User $account, Order $order, int $version): Closure => fn (): array => $this->outcome(
        $this->actingAs($account)->postJson("/api/v1/client/orders/{$order->public_id}/cancel", ['version' => $version]),
    );
    $this->close = fn (BusinessDay $day): Closure => fn (): array => ['closed' => app(DayCalendar::class)->close($day)];

    $this->numbers = fn (int $count, string $prefix = 'RMK-261008'): array => array_map(fn (int $n) => sprintf('%s-%05d', $prefix, $n), range(1, $count));
});

afterEach(function () {
    $this->concurrencyTearDown();
});

describe('submissions of the same client', function () {
    it('creates one order only when the accounts of a client submit together with one order a day (RG-C04)', function () {
        ($this->settings)(['max_per_day' => 1]);
        $accounts = ($this->accounts)($this->bounded, 4);

        $results = $this->parallel(array_map(fn (User $account) => ($this->post)($account, [[$this->pomme, 5]]), $accounts));

        $this->expectClean($results);
        expect(collect($this->statusesOf($results))->sort()->values()->all())->toBe([201, 409, 409, 409])
            ->and(collect($results)->pluck('value.code')->filter()->unique()->values()->all())->toBe(['MAX_ORDERS_REACHED'])
            ->and(collect($results)->pluck('value.body.max_orders_per_day')->filter()->unique()->values()->all())->toBe([1])
            ->and(Order::query()->where('client_id', $this->bounded->id)->count())->toBe(1);
    });

    it('never lets the orders of a client exceed his limit with two orders a day (RG-C03 au cumul, §5.8)', function () {
        ($this->settings)(['max_per_day' => 2]);
        $accounts = ($this->accounts)($this->bounded, 4);

        // Borne 50 : 30 + 30 dépasserait ; deux commandes au plus.
        $results = $this->parallel(array_map(
            fn (User $account, int $quantity) => ($this->post)($account, [[$this->orange, $quantity]]),
            $accounts,
            [30, 30, 20, 20],
        ));

        $this->expectClean($results);
        $orders = Order::query()->where('client_id', $this->bounded->id)->with('lines')->get();
        $requested = $orders->sum(fn (Order $order) => $order->lines->sum('requested_qty'));
        expect(collect($this->statusesOf($results))->sort()->values()->all())->toBe([201, 201, 409, 409])
            ->and(collect($results)->pluck('value.code')->filter()->diff(['MAX_ORDERS_REACHED', 'CLIENT_LIMIT_EXCEEDED'])->all())->toBe([])
            ->and($orders)->toHaveCount(2)
            ->and($requested)->toBeLessThanOrEqual(50)
            ->and(app(OrderUsage::class)->forClient($this->day, $this->bounded->id))->toBe([$this->orange->id => ['pending' => $requested, 'validated' => 0]]);
        // Un refus sur la borne donne le disponible relu après le verrou : 50 − (engagé au moment du contrôle).
        foreach (collect($results)->where('value.code', 'CLIENT_LIMIT_EXCEEDED') as $refused) {
            expect($refused['value']['body']['violations'][0])->toMatchArray(['product_id' => $this->orange->id, 'max_allowed' => 20]);
        }
    });

    it('creates one order for the same request id sent together from every account of the client (RG-C07, I-54)', function () {
        ($this->settings)(['max_per_day' => 5]);
        $accounts = ($this->accounts)($this->bounded, 4);
        $requestId = (string) Str::uuid();

        $results = $this->parallel(array_map(fn (User $account) => ($this->post)($account, [[$this->pomme, 5]], $requestId), $accounts));

        $this->expectClean($results);
        $order = Order::query()->where('client_id', $this->bounded->id)->sole();
        expect(collect($this->statusesOf($results))->sort()->values()->all())->toBe([200, 200, 200, 201])
            ->and(collect($results)->pluck('value.body.id')->unique()->values()->all())->toBe([$order->public_id])
            ->and($order->number)->toBe('RMK-261008-00001')
            ->and($order->lines()->count())->toBe(1);
    });
});

describe('submissions of different clients', function () {
    it('lets clients of several regions submit at the same instant: all succeed, numbers distinct and consecutive (I-55)', function (int $centre, int $est, int $rounds, bool $rendezvous) {
        ($this->settings)(['max_per_day' => $rounds]);
        $accounts = [...($this->clients)($centre, $this->centre), ...($this->clients)($est, $this->est)];

        $numbers = [];
        for ($round = 0; $round < $rounds; $round++) {
            // Rendez-vous : tous les envois tiennent la journée en partagé, puis créent leur commande et prennent un numéro ensemble.
            $flags = array_map(fn () => $this->flag(), $accounts);
            $results = $this->parallel(array_map(function (User $account, string $flag) use ($flags, $rendezvous): Closure {
                $post = ($this->post)($account, [[$this->orange, 3], [$this->pomme, 2]]);

                return $rendezvous ? $this->meeting($this::LOCK_DAY_SHARED, $flag, $flags, $post) : $post;
            }, $accounts, $flags));

            $this->expectClean($results);
            expect($this->statusesOf($results))->each->toBe(201);
            $numbers = [...$numbers, ...collect($results)->pluck('value.body.number')->all()];
        }

        $count = count($accounts) * $rounds;
        sort($numbers);
        expect($numbers)->toBe(($this->numbers)($count))
            ->and(Order::query()->where('business_day_id', $this->day->id)->orderBy('number')->pluck('number')->all())->toBe(($this->numbers)($count))
            ->and(DB::table('order_number_sequences')->where('business_day_id', $this->day->id)->value('last_number'))->toBe($count)
            ->and(DB::table('order_lines')->count())->toBe($count * 2);
    })->with([
        'deux clients' => [2, 0, 1, false],
        'deux clients, au même point' => [2, 0, 3, true],
        'six clients de deux régions, trois vagues' => [4, 2, 3, false],
        'six clients de deux régions, au même point' => [4, 2, 3, true],
    ]);
});

describe('modification and cancellation', function () {
    it('lets one of a modification and a cancellation of the same order win, the other refused (§5.8, RG-C05)', function () {
        ($this->settings)(['max_per_day' => 5]);
        $pairs = array_map(fn (User $account) => [$account, ($this->sent)($account, [[$this->orange, 10]])], ($this->clients)(3, $this->centre));

        $tasks = [];
        foreach ($pairs as [$account, $order]) {
            $tasks[] = ($this->patch)($account, $order, 1, [[$this->orange, 12], [$this->pomme, 1]]);
            $tasks[] = ($this->cancel)($account, $order, 1);
        }
        $results = $this->parallel($tasks);

        $this->expectClean($results);
        foreach ($pairs as $index => [, $order]) {
            [$patch, $cancel] = [$results[$index * 2]['value'], $results[$index * 2 + 1]['value']];
            $order->refresh();
            expect($order->version)->toBe(2)->and($order->history()->count())->toBe(2);
            if ($patch['status'] === 200) {
                // La modification d'abord : l'annulation porte une version périmée.
                expect([$cancel['status'], $cancel['code'], $cancel['body']['version'] ?? null])->toBe([409, 'VERSION_CONFLICT', 2])
                    ->and($order->status)->toBe(OrderStatus::Submitted)
                    ->and($order->lines()->pluck('requested_qty', 'product_id')->all())->toEqual([$this->orange->id => 12, $this->pomme->id => 1]);
            } else {
                // L'annulation d'abord : la commande annulée n'est plus modifiable.
                expect([$patch['status'], $patch['code']])->toBe([409, 'INVALID_TRANSITION'])
                    ->and($cancel['status'])->toBe(200)
                    ->and($order->status)->toBe(OrderStatus::Cancelled)
                    ->and($order->lines()->pluck('requested_qty', 'product_id')->all())->toEqual([$this->orange->id => 10]);
            }
        }
    });

    it('lets one of two modifications with the same version win (RG-C05, §5.8)', function () {
        [$account, $second] = ($this->accounts)($this->bounded, 2);
        $order = ($this->sent)($account, [[$this->orange, 10]]);

        $results = $this->parallel([
            ($this->patch)($account, $order, 1, [[$this->orange, 40]]),
            ($this->patch)($second, $order, 1, [[$this->orange, 45]]),
        ]);

        $this->expectClean($results);
        $values = array_column($results, 'value');
        expect(collect($values)->pluck('status')->sort()->values()->all())->toBe([200, 409])
            ->and(collect($values)->firstWhere('status', 409))->code->toBe('VERSION_CONFLICT')
            ->and($order->fresh()->version)->toBe(2)
            ->and($order->lines()->value('requested_qty'))->toBe(collect($values)->firstWhere('status', 200)['body']['lines'][0]['requested_qty']);
    });
});

describe('closing of the day', function () {
    it('leaves no submitted order when submissions race the closing (§4.4, I-61)', function (string $first) {
        ($this->settings)(['max_per_day' => 5]);
        $accounts = ($this->clients)(4, $this->centre);
        // « envois » : la clôture part quand chaque envoi tient la journée en partagé ; « clôture » : l'inverse.
        $flags = array_map(fn () => $this->flag(), $accounts);
        $closing = $this->flag();
        $submit = fn (User $account, string $flag) => match ($first) {
            'envois' => $this->holding($this::LOCK_DAY_SHARED, $flag, ($this->post)($account, [[$this->orange, 5]])),
            'clôture' => $this->after([$closing], ($this->post)($account, [[$this->orange, 5]])),
            'course' => ($this->post)($account, [[$this->orange, 5]]),
        };
        $close = match ($first) {
            'envois' => $this->after($flags, ($this->close)($this->day)),
            'clôture' => $this->holding($this::LOCK_DAY_EXCLUSIVE, $closing, ($this->close)($this->day)),
            'course' => ($this->close)($this->day),
        };

        $results = $this->parallel([...array_map($submit, $accounts, $flags), $close]);

        $this->expectClean($results);
        $created = collect(array_slice($results, 0, 4))->pluck('value')->where('status', 201);
        $refused = collect(array_slice($results, 0, 4))->pluck('value')->where('status', '!=', 201);
        $orders = Order::query()->where('business_day_id', $this->day->id)->orderBy('number')->get();
        expect($results[4]['value'])->toBe(['closed' => true])
            ->and($this->day->fresh()->isOpen())->toBeFalse()
            ->and($orders->where('status', OrderStatus::Submitted))->toBeEmpty()
            ->and($refused->pluck('code')->unique()->diff(['DAY_NOT_OPEN'])->all())->toBe([])
            // Envoyée avant la clôture : expirée par elle, avec son historique.
            ->and($orders->pluck('public_id')->sort()->values()->all())->toBe($created->pluck('body.id')->sort()->values()->all())
            ->and($orders->pluck('number')->all())->toBe($created->isEmpty() ? [] : ($this->numbers)($created->count()));
        foreach ($orders as $order) {
            expect($order->status)->toBe(OrderStatus::Expired)
                ->and($order->version)->toBe(2)
                ->and($order->history()->pluck('to_status')->map->value->all())->toBe(['SUBMITTED', 'EXPIRED']);
        }
        match ($first) {
            'envois' => expect($created)->toHaveCount(4),
            'clôture' => expect($refused)->toHaveCount(4),
            'course' => null,
        };
    })->with(['envois', 'clôture', 'course']);

    it('always closes the day when a modification and a cancellation race the closing (§4.4, I-61)', function (string $first) {
        ($this->settings)(['max_per_day' => 5]);
        [$patcher, $canceller] = ($this->clients)(2, $this->centre);
        $patched = ($this->sent)($patcher, [[$this->orange, 10]]);
        $cancelled = ($this->sent)($canceller, [[$this->pomme, 10]]);
        // « écritures » : la clôture part quand les deux écritures tiennent la journée en partagé ; « clôture » : l'inverse.
        $flags = [$this->flag(), $this->flag()];
        $closing = $this->flag();
        $writer = fn (Closure $write, string $flag) => match ($first) {
            'écritures' => $this->holding($this::LOCK_DAY_SHARED, $flag, $write),
            'clôture' => $this->after([$closing], $write),
            'course' => $write,
        };
        $close = match ($first) {
            'écritures' => $this->after($flags, ($this->close)($this->day)),
            'clôture' => $this->holding($this::LOCK_DAY_EXCLUSIVE, $closing, ($this->close)($this->day)),
            'course' => ($this->close)($this->day),
        };

        $results = $this->parallel([
            $writer(($this->patch)($patcher, $patched, 1, [[$this->orange, 12]]), $flags[0]),
            $writer(($this->cancel)($canceller, $cancelled, 1), $flags[1]),
            $close,
        ]);

        $this->expectClean($results);
        [$patch, $cancel] = [$results[0]['value'], $results[1]['value']];
        expect($results[2]['value'])->toBe(['closed' => true])
            ->and($this->day->fresh()->isOpen())->toBeFalse()
            ->and(Order::query()->where('business_day_id', $this->day->id)->where('status', OrderStatus::Submitted->value)->count())->toBe(0);

        // Modification validée avant la clôture : expirée ensuite (version 3) ; sinon refusée proprement.
        $patched->refresh();
        if ($patch['status'] === 200) {
            expect($patched)->status->toBe(OrderStatus::Expired)->version->toBe(3)
                ->and($patched->history()->pluck('to_status')->map->value->all())->toBe(['SUBMITTED', 'SUBMITTED', 'EXPIRED'])
                ->and($patched->lines()->value('requested_qty'))->toBe(12);
        } else {
            expect($patch['code'])->toBeIn(['INVALID_TRANSITION', 'DAY_NOT_OPEN'])
                ->and($patched)->status->toBe(OrderStatus::Expired)->version->toBe(2)
                ->and($patched->lines()->value('requested_qty'))->toBe(10);
        }
        // Annulation validée avant la clôture : la commande reste annulée ; sinon refusée, la commande expire.
        $cancelled->refresh();
        if ($cancel['status'] === 200) {
            expect($cancelled)->status->toBe(OrderStatus::Cancelled)->version->toBe(2)
                ->and($cancelled->history()->pluck('to_status')->map->value->all())->toBe(['SUBMITTED', 'CANCELLED']);
        } else {
            expect($cancel['code'])->toBeIn(['INVALID_TRANSITION', 'DAY_NOT_OPEN'])
                ->and($cancelled)->status->toBe(OrderStatus::Expired)->version->toBe(2);
        }
        match ($first) {
            'écritures' => expect([$patch['status'], $cancel['status']])->toBe([200, 200]),
            'clôture' => expect([$patch['status'], $patch['code'], $cancel['status'], $cancel['code']])->toBe([409, 'INVALID_TRANSITION', 409, 'INVALID_TRANSITION']),
            'course' => null,
        };
    })->with(['écritures', 'clôture', 'course']);

    it('repeats the free race of the writers and the closing on several days', function () {
        ($this->settings)(['max_per_day' => 5]);
        [$patcher, $canceller, $submitter] = ($this->clients)(3, $this->centre);

        foreach (['2026-10-08', '2026-10-11', '2026-10-12', '2026-10-13'] as $date) {
            $day = $date === '2026-10-08' ? $this->day : ($this->open)($date);
            $patched = ($this->sent)($patcher, [[$this->orange, 10]], $date);
            $cancelled = ($this->sent)($canceller, [[$this->pomme, 10]], $date);

            $results = $this->parallel([
                ($this->patch)($patcher, $patched, 1, [[$this->orange, 12]]),
                ($this->cancel)($canceller, $cancelled, 1),
                ($this->post)($submitter, [[$this->orange, 5]], date: $date),
                ($this->close)($day),
            ]);

            $this->expectClean($results);
            expect($results[3]['value'])->toBe(['closed' => true])
                ->and(collect($results)->take(3)->pluck('value.status')->diff([200, 201, 409])->all())->toBe([])
                ->and(collect($results)->take(3)->pluck('value.code')->filter()->diff(['INVALID_TRANSITION', 'DAY_NOT_OPEN'])->all())->toBe([])
                ->and(Order::query()->where('business_day_id', $day->id)->where('status', OrderStatus::Submitted->value)->count())->toBe(0)
                ->and($cancelled->fresh()->status)->toBe($results[1]['value']['status'] === 200 ? OrderStatus::Cancelled : OrderStatus::Expired)
                ->and($patched->fresh()->version)->toBe($results[0]['value']['status'] === 200 ? 3 : 2);
        }
    });
});
