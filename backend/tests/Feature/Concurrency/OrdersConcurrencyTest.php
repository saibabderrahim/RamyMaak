<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderUsage;
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
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// Accès simultanés aux commandes (§5.8 ; contrat du lot 5, I-61), sur MySQL
// seulement (verrous InnoDB, REPEATABLE READ) : des processus parallèles
// (pcntl_fork), chacun avec sa propre connexion, partent au même signal et
// passent par l'API (POST, PATCH, annulation) ou par DayCalendar::close.
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
// rôles et permissions semés par la migration).

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! str_contains(DB::getDatabaseName(), 'test')) {
        $this->markTestSkipped('Accès simultanés : base de test MySQL seulement (verrous InnoDB).');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Accès simultanés : extensions pcntl et posix requises.');
    }
    $this->concurrent = true;

    // Fin de la transaction de RefreshDatabase : les processus parallèles ont
    // chacun leur connexion. Gestionnaire de transactions de production : les
    // rappels « après validation » attendent la vraie validation.
    DB::commit();
    $transactions = new DatabaseTransactionsManager;
    app()->instance('db.transactions', $transactions);
    DB::connection()->setTransactionManager($transactions);

    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00. L'horloge figée est héritée par les processus.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
    ]);
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

    // Tâches exécutées dans un processus parallèle : la réponse HTTP résumée.
    $this->outcome = fn (TestResponse $response): array => [
        'status' => $response->status(),
        'code' => $response->json('code'),
        'body' => $response->json(),
        // Erreur interne seulement (un refus métier porte aussi son exception) : interblocage non rattrapé, etc.
        'exception' => $response->status() < 500 || $response->exception === null ? null : $response->exception::class.' : '.$response->exception->getMessage(),
    ];
    $this->post = function (User $account, array $lines, ?string $requestId = null, string $date = '2026-10-08'): Closure {
        $body = ['client_request_id' => $requestId ?? (string) Str::uuid(), 'day' => $date, 'comment' => null, 'lines' => ($this->lines)($lines)];

        return fn (): array => ($this->outcome)($this->actingAs($account)->postJson('/api/v1/client/orders', $body));
    };
    $this->patch = fn (User $account, Order $order, int $version, array $lines): Closure => fn (): array => ($this->outcome)(
        $this->actingAs($account)->patchJson("/api/v1/client/orders/{$order->public_id}", [
            'version' => $version, 'comment' => null, 'lines' => ($this->lines)($lines),
        ]),
    );
    $this->cancel = fn (User $account, Order $order, int $version): Closure => fn (): array => ($this->outcome)(
        $this->actingAs($account)->postJson("/api/v1/client/orders/{$order->public_id}/cancel", ['version' => $version]),
    );
    $this->close = fn (BusinessDay $day): Closure => fn (): array => ['closed' => app(DayCalendar::class)->close($day)];

    // Ordre d'arrivée imposé : le processus qui exécute l'instruction `$pattern`
    // (verrou pris) le signale par un fichier, puis garde son verrou
    // `$milliseconds` ; l'autre attend le signal avant de partir.
    $this->flag = function (): string {
        $path = sys_get_temp_dir().'/rmk-concurrency-'.Str::random(16);
        $this->flags[] = $path;

        return $path;
    };
    $this->holdAfter = function (string $pattern, string $flag, int $milliseconds = 400): void {
        $held = false;
        DB::listen(function (QueryExecuted $query) use (&$held, $pattern, $flag, $milliseconds): void {
            if (! $held && preg_match($pattern, $query->sql) === 1) {
                $held = true;
                touch($flag);
                usleep($milliseconds * 1000);
            }
        });
    };
    $this->waitFor = function (string ...$flags): void {
        $deadline = microtime(true) + 15;
        foreach ($flags as $flag) {
            while (! file_exists($flag)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Signal attendu absent : '.$flag);
                }
                usleep(2000);
                clearstatcache();
            }
        }
    };
    // Tâche qui garde son verrou après `$pattern` en le signalant ; tâche qui attend des signaux avant de partir.
    $this->holding = fn (string $pattern, string $flag, Closure $task): Closure => function () use ($pattern, $flag, $task): array {
        ($this->holdAfter)($pattern, $flag);

        return $task();
    };
    $this->after = fn (array $flags, Closure $task): Closure => function () use ($flags, $task): array {
        ($this->waitFor)(...$flags);

        return $task();
    };
    // Rendez-vous : chaque tâche s'arrête après `$pattern` (verrou pris) jusqu'à ce que toutes y soient, puis toutes repartent ensemble.
    $this->meeting = fn (string $pattern, string $flag, array $flags, Closure $task): Closure => function () use ($pattern, $flag, $flags, $task): array {
        $met = false;
        DB::listen(function (QueryExecuted $query) use (&$met, $pattern, $flag, $flags): void {
            if (! $met && preg_match($pattern, $query->sql) === 1) {
                $met = true;
                touch($flag);
                ($this->waitFor)(...$flags);
            }
        });

        return $task();
    };
    // Verrou partagé sur la journée (envoi, modification, annulation) ; verrou exclusif de la clôture.
    $this->sharedDayLock = '/^select .* from `business_days` .* lock in share mode$/';
    $this->exclusiveDayLock = '/^select .* from `business_days` .* for update$/';

    /*
     * Exécute chaque tâche dans un processus enfant (pcntl_fork) : connexion
     * propre (DB::reconnect), puis barrière (tous prêts avant le départ),
     * puis la tâche. Rend, dans l'ordre des tâches : `value` (résultat de la
     * tâche) ou `error`, et `attempts` (transactions commencées : plus d'une
     * par écriture = transaction rejouée après un interblocage).
     */
    $this->parallel = function (array $tasks): array {
        // Caches en mémoire (permissions, paramètres) remplis avant la copie des processus.
        app(PermissionRegistrar::class)->getPermissions();
        app(Settings::class)->all();
        // Aucune connexion partagée entre processus : chacun ouvre la sienne.
        DB::disconnect();

        $children = [];
        foreach (array_values($tasks) as $index => $task) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork a échoué.');
            }
            if ($pid === 0) {
                // Processus enfant : ne rend jamais la main au lanceur de tests.
                try {
                    fclose($pair[0]);
                    $result = ['attempts' => 0];
                    try {
                        DB::reconnect();
                        DB::select('SELECT 1');
                        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$result): void {
                            $result['attempts']++;
                        });
                        fwrite($pair[1], "ready\n");
                        fgets($pair[1]);
                        $result['value'] = $task();
                    } catch (Throwable $e) {
                        $result['error'] = $e::class.' : '.$e->getMessage();
                    }
                    fwrite($pair[1], json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
                    DB::disconnect();
                } finally {
                    posix_kill(getmypid(), SIGKILL);
                }
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 120);
            $children[$index] = [$pid, $pair[0]];
        }

        $kill = function () use ($children): void {
            foreach ($children as [$pid]) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        };
        foreach ($children as $index => [, $socket]) {
            if (fgets($socket) !== "ready\n") {
                $kill();
                throw new RuntimeException("Processus {$index} : non prêt.");
            }
        }
        foreach ($children as [, $socket]) {
            fwrite($socket, "go\n");
        }
        $results = [];
        foreach ($children as $index => [$pid, $socket]) {
            $line = fgets($socket);
            fclose($socket);
            if ($line === false) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
            $results[$index] = $line === false ? ['error' => "Processus {$index} : sans réponse.", 'attempts' => null] : json_decode($line, true);
        }

        return $results;
    };

    // Aucune tâche en échec, une seule transaction par processus (aucun interblocage rejoué).
    $this->expectClean = function (array $results): void {
        expect(array_filter(array_column($results, 'error')))->toBe([])
            ->and(array_filter(array_column(array_column($results, 'value'), 'exception')))->toBe([])
            ->and(array_column($results, 'attempts'))->toBe(array_fill(0, count($results), 1));
    };
    $this->statusesOf = fn (array $results): array => array_column(array_column($results, 'value'), 'status');
    $this->numbers = fn (int $count, string $prefix = 'RMK-261008'): array => array_map(fn (int $n) => sprintf('%s-%05d', $prefix, $n), range(1, $count));
});

afterEach(function () {
    if (! ($this->concurrent ?? false)) {
        return;
    }
    foreach ($this->flags ?? [] as $flag) {
        @unlink($flag);
    }

    // Base de test seulement (Schema::getTables sans schéma lirait toutes les bases du compte).
    $kept = ['migrations', 'roles', 'permissions', 'role_has_permissions'];
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Schema::getTableListing(DB::getDatabaseName(), false) as $table) {
        if (! in_array($table, $kept, true)) {
            DB::statement("TRUNCATE TABLE `{$table}`");
        }
    }
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    // RefreshDatabase annule en fin de test une transaction ouverte ; sans elle, il referait la migration au test suivant.
    DB::beginTransaction();
});

describe('submissions of the same client', function () {
    it('creates one order only when the accounts of a client submit together with one order a day (RG-C04)', function () {
        ($this->settings)(['max_per_day' => 1]);
        $accounts = ($this->accounts)($this->bounded, 4);

        $results = ($this->parallel)(array_map(fn (User $account) => ($this->post)($account, [[$this->pomme, 5]]), $accounts));

        ($this->expectClean)($results);
        expect(collect(($this->statusesOf)($results))->sort()->values()->all())->toBe([201, 409, 409, 409])
            ->and(collect($results)->pluck('value.code')->filter()->unique()->values()->all())->toBe(['MAX_ORDERS_REACHED'])
            ->and(collect($results)->pluck('value.body.max_orders_per_day')->filter()->unique()->values()->all())->toBe([1])
            ->and(Order::query()->where('client_id', $this->bounded->id)->count())->toBe(1);
    });

    it('never lets the orders of a client exceed his limit with two orders a day (RG-C03 au cumul, §5.8)', function () {
        ($this->settings)(['max_per_day' => 2]);
        $accounts = ($this->accounts)($this->bounded, 4);

        // Borne 50 : 30 + 30 dépasserait ; deux commandes au plus.
        $results = ($this->parallel)(array_map(
            fn (User $account, int $quantity) => ($this->post)($account, [[$this->orange, $quantity]]),
            $accounts,
            [30, 30, 20, 20],
        ));

        ($this->expectClean)($results);
        $orders = Order::query()->where('client_id', $this->bounded->id)->with('lines')->get();
        $requested = $orders->sum(fn (Order $order) => $order->lines->sum('requested_qty'));
        expect(collect(($this->statusesOf)($results))->sort()->values()->all())->toBe([201, 201, 409, 409])
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

        $results = ($this->parallel)(array_map(fn (User $account) => ($this->post)($account, [[$this->pomme, 5]], $requestId), $accounts));

        ($this->expectClean)($results);
        $order = Order::query()->where('client_id', $this->bounded->id)->sole();
        expect(collect(($this->statusesOf)($results))->sort()->values()->all())->toBe([200, 200, 200, 201])
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
            $flags = array_map(fn () => ($this->flag)(), $accounts);
            $results = ($this->parallel)(array_map(function (User $account, string $flag) use ($flags, $rendezvous): Closure {
                $post = ($this->post)($account, [[$this->orange, 3], [$this->pomme, 2]]);

                return $rendezvous ? ($this->meeting)($this->sharedDayLock, $flag, $flags, $post) : $post;
            }, $accounts, $flags));

            ($this->expectClean)($results);
            expect(($this->statusesOf)($results))->each->toBe(201);
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
        $results = ($this->parallel)($tasks);

        ($this->expectClean)($results);
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

        $results = ($this->parallel)([
            ($this->patch)($account, $order, 1, [[$this->orange, 40]]),
            ($this->patch)($second, $order, 1, [[$this->orange, 45]]),
        ]);

        ($this->expectClean)($results);
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
        $flags = array_map(fn () => ($this->flag)(), $accounts);
        $closing = ($this->flag)();
        $submit = fn (User $account, string $flag) => match ($first) {
            'envois' => ($this->holding)($this->sharedDayLock, $flag, ($this->post)($account, [[$this->orange, 5]])),
            'clôture' => ($this->after)([$closing], ($this->post)($account, [[$this->orange, 5]])),
            'course' => ($this->post)($account, [[$this->orange, 5]]),
        };
        $close = match ($first) {
            'envois' => ($this->after)($flags, ($this->close)($this->day)),
            'clôture' => ($this->holding)($this->exclusiveDayLock, $closing, ($this->close)($this->day)),
            'course' => ($this->close)($this->day),
        };

        $results = ($this->parallel)([...array_map($submit, $accounts, $flags), $close]);

        ($this->expectClean)($results);
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
        $flags = [($this->flag)(), ($this->flag)()];
        $closing = ($this->flag)();
        $writer = fn (Closure $write, string $flag) => match ($first) {
            'écritures' => ($this->holding)($this->sharedDayLock, $flag, $write),
            'clôture' => ($this->after)([$closing], $write),
            'course' => $write,
        };
        $close = match ($first) {
            'écritures' => ($this->after)($flags, ($this->close)($this->day)),
            'clôture' => ($this->holding)($this->exclusiveDayLock, $closing, ($this->close)($this->day)),
            'course' => ($this->close)($this->day),
        };

        $results = ($this->parallel)([
            $writer(($this->patch)($patcher, $patched, 1, [[$this->orange, 12]]), $flags[0]),
            $writer(($this->cancel)($canceller, $cancelled, 1), $flags[1]),
            $close,
        ]);

        ($this->expectClean)($results);
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

            $results = ($this->parallel)([
                ($this->patch)($patcher, $patched, 1, [[$this->orange, 12]]),
                ($this->cancel)($canceller, $cancelled, 1),
                ($this->post)($submitter, [[$this->orange, 5]], date: $date),
                ($this->close)($day),
            ]);

            ($this->expectClean)($results);
            expect($results[3]['value'])->toBe(['closed' => true])
                ->and(collect($results)->take(3)->pluck('value.status')->diff([200, 201, 409])->all())->toBe([])
                ->and(collect($results)->take(3)->pluck('value.code')->filter()->diff(['INVALID_TRANSITION', 'DAY_NOT_OPEN'])->all())->toBe([])
                ->and(Order::query()->where('business_day_id', $day->id)->where('status', OrderStatus::Submitted->value)->count())->toBe(0)
                ->and($cancelled->fresh()->status)->toBe($results[1]['value']['status'] === 200 ? OrderStatus::Cancelled : OrderStatus::Expired)
                ->and($patched->fresh()->version)->toBe($results[0]['value']['status'] === 200 ? 3 : 2);
        }
    });
});
