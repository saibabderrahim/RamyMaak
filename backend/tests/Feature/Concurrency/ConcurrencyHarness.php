<?php

namespace Tests\Feature\Concurrency;

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
use App\Http\Problems\ApiException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * Outillage des tests d'accès simultanés (§5.8 ; I-61, I-67), sur MySQL
 * seulement (verrous InnoDB, REPEATABLE READ) : des processus parallèles
 * (pcntl_fork), chacun avec sa propre connexion, partent au même signal et
 * passent par l'API (POST, PATCH…) ou par un service (publication, clôture).
 * Commun aux tests des lots 5 et 6 ; chaque fichier l'importe par `uses()`,
 * appelle `concurrencySetUp()` avant ses données et `concurrencyTearDown()`
 * après chaque test.
 *
 * Les données sont validées (COMMIT) pour être vues des autres connexions,
 * puis effacées après chaque test (TRUNCATE de la base de test, sauf les
 * rôles et permissions semés par la migration et les motifs, insérés par la
 * leur : sans eux, tous les fichiers suivants de la même exécution perdent
 * leurs motifs).
 *
 * Ordres imposés, pour prouver chaque ordre d'arrivée et non seulement la
 * course libre :
 * - `holding` : la tâche garde son verrou après l'instruction `$pattern` et
 *   le signale par un fichier ;
 * - `after` : la tâche attend des signaux avant de partir ;
 * - `meeting` / `meetAfter` : rendez-vous, toutes les tâches s'arrêtent après
 *   `$pattern` (verrou pris) jusqu'à ce que toutes y soient, puis repartent
 *   ensemble. Ne jamais l'imposer à des tâches qui se bloquent entre elles
 *   sur ce même verrou (la seconde n'y arriverait jamais).
 *
 * @mixin TestCase
 */
trait ConcurrencyHarness
{
    /**
     * Événements de domaine (lots 3 à 6) : factices dans les processus
     * parallèles. Les écouteurs (notifications, diffusion) sortent du sujet
     * (verrous des écritures) et ralentiraient les rendez-vous.
     */
    public const FAKED_EVENTS = [
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
        OrderValidated::class, OrderRejected::class, OrderCancelledByAdv::class, OrdersProcessed::class, OrderShippingUpdated::class,
    ];

    /** Verrou exclusif du client (première instruction des écrivains de commande, I-67). */
    public const LOCK_CLIENT = '/^select .* from `clients` .* for update$/';

    /** Verrou partagé sur la journée (écrivains de commande) ; exclusif : clôture, publication des quotas. */
    public const LOCK_DAY_SHARED = '/^select .* from `business_days` .* lock in share mode$/';

    public const LOCK_DAY_EXCLUSIVE = '/^select .* from `business_days` .* for update$/';

    /** Journée régionale : partagée par la validation, exclusive pour les bornes (LimitBook). */
    public const LOCK_REGION_DAY_SHARED = '/^select .* from `region_days` .* lock in share mode$/';

    public const LOCK_REGION_DAY_EXCLUSIVE = '/^select .* from `region_days` .* for update$/';

    /** Commande(s) FOR UPDATE. */
    public const LOCK_ORDER = '/^select .* from `orders` .* for update$/';

    /** Bornes du client FOR UPDATE (validation). */
    public const LOCK_BORNE = '/^select .* from `client_limits` .* for update$/';

    /** Compteurs de la région FOR UPDATE (validation) ; mise à jour du compteur (annulation ADV, QuotaLedger::release). */
    public const LOCK_COUNTER = '/^select .* from `region_quotas` .* for update$/';

    public const UPDATE_COUNTER = '/^update `region_quotas` set/';

    /** Vrai une fois la base préparée par concurrencySetUp() (le démontage n'agit pas sur un test ignoré). */
    public bool $concurrent = false;

    /** @var list<string> fichiers-signaux créés par le test */
    public array $flags = [];

    /**
     * Début de test : ignoré hors base de test MySQL ; fin de la transaction
     * de RefreshDatabase (les processus parallèles ont chacun leur
     * connexion) ; gestionnaire de transactions de production (les rappels
     * « après validation » attendent la vraie validation) ; événements
     * factices ; jeudi 8 octobre 2026, 09:30 à Alger (commande jusqu'à 13:00,
     * validation jusqu'à 15:00), horloge héritée par les processus.
     */
    public function concurrencySetUp(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! str_contains(DB::getDatabaseName(), 'test')) {
            $this->markTestSkipped('Accès simultanés : base de test MySQL seulement (verrous InnoDB).');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Accès simultanés : extensions pcntl et posix requises.');
        }
        $this->concurrent = true;

        DB::commit();
        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
        Event::fake(self::FAKED_EVENTS);
    }

    /** Fin de test : fichiers-signaux, puis base de test vidée (sauf rôles, permissions et motifs). */
    public function concurrencyTearDown(): void
    {
        if (! $this->concurrent) {
            return;
        }
        foreach ($this->flags as $flag) {
            @unlink($flag);
        }

        // Base de test seulement (Schema::getTables sans schéma lirait toutes les bases du compte).
        // Motifs : insérés par leur migration (lot 6), gardés pour les tests suivants.
        $kept = ['migrations', 'roles', 'permissions', 'role_has_permissions', 'reason_codes'];
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Schema::getTableListing(DB::getDatabaseName(), false) as $table) {
            if (! in_array($table, $kept, true)) {
                DB::statement("TRUNCATE TABLE `{$table}`");
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        // RefreshDatabase annule en fin de test une transaction ouverte ; sans elle, il referait la migration au test suivant.
        DB::beginTransaction();
    }

    /**
     * Tâche exécutée dans un processus parallèle : la réponse HTTP résumée.
     *
     * @return array{status: int, code: ?string, body: mixed, exception: ?string}
     */
    public function outcome(TestResponse $response): array
    {
        return [
            'status' => $response->status(),
            'code' => $response->json('code'),
            'body' => $response->json(),
            // Erreur interne seulement (un refus métier porte aussi son exception) : interblocage non rattrapé, etc.
            'exception' => $response->status() < 500 || $response->exception === null ? null : $response->exception::class.' : '.$response->exception->getMessage(),
        ];
    }

    /**
     * Tâche qui appelle un service (publication, clôture…) et rend le même
     * résumé qu'une réponse HTTP : 200 et la valeur rendue si c'est un
     * tableau, sinon le refus métier (ApiException : son statut, son code et
     * ses membres ; ValidationException : 422 et ses erreurs). Toute autre
     * exception (interblocage non rattrapé…) remonte : `expectClean` la voit.
     */
    public function serviceTask(Closure $call): Closure
    {
        return function () use ($call): array {
            try {
                $value = $call();

                return ['status' => 200, 'code' => null, 'body' => is_array($value) ? $value : ['value' => $value], 'exception' => null];
            } catch (ApiException $e) {
                return ['status' => $e->status(), 'code' => $e->errorCode->value, 'body' => $e->extra, 'exception' => null];
            } catch (ValidationException $e) {
                return ['status' => 422, 'code' => 'VALIDATION_FAILED', 'body' => ['errors' => $e->errors()], 'exception' => null];
            }
        };
    }

    /** Fichier-signal (supprimé en fin de test). */
    public function flag(): string
    {
        $path = sys_get_temp_dir().'/rmk-concurrency-'.Str::random(16);
        $this->flags[] = $path;

        return $path;
    }

    /**
     * Ordre d'arrivée imposé : le processus qui exécute l'instruction
     * `$pattern` (verrou pris) le signale par un fichier, puis garde son
     * verrou `$milliseconds`.
     */
    public function holdAfter(string $pattern, string $flag, int $milliseconds = 400): void
    {
        $held = false;
        DB::listen(function (QueryExecuted $query) use (&$held, $pattern, $flag, $milliseconds): void {
            if (! $held && preg_match($pattern, $query->sql) === 1) {
                $held = true;
                touch($flag);
                usleep($milliseconds * 1000);
            }
        });
    }

    public function waitFor(string ...$flags): void
    {
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
    }

    /** Tâche qui garde son verrou après `$pattern` en le signalant. */
    public function holding(string $pattern, string $flag, Closure $task): Closure
    {
        return function () use ($pattern, $flag, $task): array {
            $this->holdAfter($pattern, $flag);

            return $task();
        };
    }

    /**
     * Tâche qui attend des signaux avant de partir.
     *
     * @param  list<string>  $flags
     */
    public function after(array $flags, Closure $task): Closure
    {
        return function () use ($flags, $task): array {
            $this->waitFor(...$flags);

            return $task();
        };
    }

    /**
     * Rendez-vous : la tâche s'arrête après `$pattern` (verrou pris) jusqu'à
     * ce que toutes celles de `$flags` y soient, puis repart avec elles.
     *
     * @param  list<string>  $flags  signaux de toutes les tâches du rendez-vous, la sienne comprise
     */
    public function meeting(string $pattern, string $flag, array $flags, Closure $task): Closure
    {
        return function () use ($pattern, $flag, $flags, $task): array {
            $met = false;
            DB::listen(function (QueryExecuted $query) use (&$met, $pattern, $flag, $flags): void {
                if (! $met && preg_match($pattern, $query->sql) === 1) {
                    $met = true;
                    touch($flag);
                    $this->waitFor(...$flags);
                }
            });

            return $task();
        };
    }

    /**
     * Rendez-vous de plusieurs tâches au même point (`$pattern`).
     *
     * @param  list<Closure>  $tasks
     * @return list<Closure>
     */
    public function meetAfter(string $pattern, array $tasks): array
    {
        $flags = array_map(fn () => $this->flag(), array_values($tasks));

        return array_map(fn (Closure $task, string $flag) => $this->meeting($pattern, $flag, $flags, $task), array_values($tasks), $flags);
    }

    /**
     * Exécute chaque tâche dans un processus enfant (pcntl_fork) : connexion
     * propre (DB::reconnect), puis barrière (tous prêts avant le départ),
     * puis la tâche. Rend, dans l'ordre des tâches : `value` (résultat de la
     * tâche) ou `error`, et `attempts` (transactions commencées : plus d'une
     * par écriture = transaction rejouée après un interblocage).
     *
     * @param  list<Closure>  $tasks
     * @return list<array{value?: mixed, error?: string, attempts: ?int}>
     */
    public function parallel(array $tasks): array
    {
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
                        // Transactions de premier niveau seulement (un niveau imbriqué n'est pas une nouvelle tentative).
                        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$result): void {
                            if ($event->connection->transactionLevel() === 1) {
                                $result['attempts']++;
                            }
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
    }

    /**
     * Aucune tâche en échec, une seule transaction par processus (aucun
     * interblocage rejoué, même rattrapé par les tentatives du service).
     *
     * @param  list<array<string, mixed>>  $results
     */
    public function expectClean(array $results): void
    {
        expect(array_filter(array_column($results, 'error')))->toBe([])
            ->and(array_filter(array_column(array_column($results, 'value'), 'exception')))->toBe([])
            ->and(array_column($results, 'attempts'))->toBe(array_fill(0, count($results), 1));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public function statusesOf(array $results): array
    {
        return array_column(array_column($results, 'value'), 'status');
    }
}
