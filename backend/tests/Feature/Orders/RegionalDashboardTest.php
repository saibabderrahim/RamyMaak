<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Limits\LimitBook;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrderRejected;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\OrderValidated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as SpatieRole;

// GET /regional/dashboard (« Ma journée », EF-REG-01 ; contrat du lot 6, §3) :
// régions du périmètre à la date demandée, statut, heures limites (validation
// et prolongations comprises), commandes par onglet, plus ancienne commande à
// valider, clients notifiés / ayant commandé / sans commande et leurs
// téléphones ; sans enveloppe `data` (I-96) ; droits et périmètre ; nombre de
// requêtes indépendant du nombre de commandes.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class, OrderValidated::class, OrderRejected::class,
    ]);
    app(Settings::class)->update(['orders' => ['max_per_day' => 3]], null);
    $at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse("2026-10-08 {$moment}", 'Africa/Algiers'));
    $this->at = $at;
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional, ['name' => 'Karim Régional']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    $this->regionalEst = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regionalEst->id, 'region_id' => $this->est->id]);

    $client = fn (string $code, array $attributes = []) => Client::factory()->create(['code' => $code, 'name' => "Client {$code}", 'region_id' => $this->centre->id, 'phone' => null, 'phone2' => null, ...$attributes]);
    $this->a = $client('C0012', ['name' => 'Superette El Baraka', 'phone' => '0550123456']);
    $this->b = $client('C0047', ['name' => 'Alimentation Nour', 'phone2' => '0660987654']);
    $this->c = $client('C0100');
    $this->g = $client('C0150');
    $this->h = $client('C0200', ['phone' => '0770000000', 'phone2' => '0550999999']);
    $this->blocked = $client('C0300', ['status' => 'BLOCKED']);
    $this->inactive = $client('C0400', ['status' => 'INACTIVE']);
    $this->f = Client::factory()->create(['code' => 'C0900', 'name' => 'Client Est', 'region_id' => $this->est->id]);
    $this->accounts = collect([$this->a, $this->b, $this->c, $this->g, $this->h, $this->f])
        ->mapWithKeys(fn (Client $c) => [$c->code => userWithRole(Role::Client, ['client_id' => $c->id])]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);

    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]]);
    $this->estDay = openOrderingDay('2026-10-08', $this->est, [[$this->orange, 200]]);
    $this->submit = fn (Client $c, ?array $lines = null): Order => app(ClientOrders::class)->submit(
        $this->accounts[$c->code],
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines ?? [[$this->orange, 5]]),
        null,
    )['order'];

    // A : une commande envoyée (la plus ancienne, 08:40) ; B : une commande validée puis une deuxième envoyée (08:50) ;
    // C : une commande rejetée seulement ; G : une commande annulée seulement ; H : aucune.
    $at('08:40');
    $this->orderA = ($this->submit)($this->a);
    $at('08:45');
    $this->orderB1 = ($this->submit)($this->b);
    $this->orderC = ($this->submit)($this->c);
    $this->orderG = ($this->submit)($this->g);
    $this->orderF = ($this->submit)($this->f);
    $at('08:50');
    validateOrder($this->orderB1);
    app(OrderDecisions::class)->reject($this->regional, $this->orderC, 1, 'DOUBLON', null);
    app(ClientOrders::class)->cancel($this->accounts['C0150'], $this->orderG->public_id, 1);
    $this->orderB2 = ($this->submit)($this->b, [[$this->pomme, 2]]);
    $at('09:30');

    $this->get = fn (string $query = '', ?User $by = null) => $this->actingAs($by ?? $this->regional)->getJson('/api/v1/regional/dashboard'.$query);
    $this->region = fn (TestResponse|array $response, string $code = 'CENTRE') => collect(is_array($response) ? $response['regions'] : $response->json('regions'))
        ->first(fn (array $region) => $region['region']['code'] === $code);
    $this->users = fn (string $role): User => match ($role) {
        'regional' => $this->regional,
        'adv' => userWithRole(Role::Adv),
        'admin' => admin(),
        'client' => $this->accounts['C0012'],
        'none' => User::factory()->create(),
    };
});

describe('rights', function () {
    it('lets the regional read his day', function () {
        ($this->get)()->assertOk()->assertJsonPath('opened', true);
    });

    it('refuses with 403 the ADV, the Admin, a client account and an account without role', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem($this->getJson('/api/v1/regional/dashboard'), 403, 'FORBIDDEN');
    })->with(['adv', 'admin', 'client', 'none']);

    it('requires an authenticated session', function () {
        expectProblem($this->getJson('/api/v1/regional/dashboard'), 401, 'UNAUTHENTICATED');
    });

    it('follows the dashboard.view permission of the regional role, not only the role', function () {
        SpatieRole::findByName(Role::Regional->value)->revokePermissionTo(Permission::DashboardView->value);

        expectProblem(($this->get)(), 403, 'FORBIDDEN');
    });

    it('checks the rights before the perimeter and the format (403 before 404 before 422)', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem($this->getJson('/api/v1/regional/dashboard?day=08/10/2026'), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/regional/dashboard?region='.$this->est->id.'&day=nope'), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/regional/dashboard?region=999999'), 403, 'FORBIDDEN');
    })->with(['adv', 'admin', 'client']);

    it('answers 404 for a region out of the perimeter, before the format of the day', function () {
        expectProblem(($this->get)('?region='.$this->est->id), 404, 'NOT_FOUND');
        expectProblem(($this->get)('?region=999999'), 404, 'NOT_FOUND');
        // Hors périmètre : le contrôle de la région passe avant celui des autres paramètres.
        expectProblem(($this->get)('?region='.$this->est->id.'&day=2026-10-09'), 404, 'NOT_FOUND');
        // Sa propre région : le format est contrôlé ensuite.
        ($this->get)('?region='.$this->centre->id)->assertOk();
    });

    it('answers 422 to a malformed day or region', function (string $query, string $field) {
        $response = ($this->get)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'journée mal formée' => ['?day=08/10/2026', 'day'],
        'journée inexistante' => ['?day=2026-02-30', 'day'],
        'journée en tableau' => ['?day[]=2026-10-08', 'day'],
        'région mal formée' => ['?region=centre', 'region'],
        'région décimale' => ['?region=1.5', 'region'],
        'région en tableau' => ['?region[]=1', 'region'],
    ]);

    it('lets a deputy read the region during his period only, and the perimeter is read at the requested day (§3.4)', function () {
        $deputy = userWithRole(Role::Regional);
        RegionAssignment::factory()->deputy('2026-10-08', '2026-10-09')->create(['user_id' => $deputy->id, 'region_id' => $this->est->id]);

        expect(collect(($this->get)('', $deputy)->assertOk()->json('regions'))->pluck('region.code')->all())->toBe(['EST']);
        // Demain (période en cours), mais la journée n'est pas ouverte : rien, sans erreur.
        ($this->get)('?day=2026-10-09', $deputy)->assertOk()->assertJsonPath('opened', false)->assertJsonPath('regions', []);
        // Après la période : pas de région, la journée ouverte reste visible pour son propriétaire.
        $past = userWithRole(Role::Regional);
        RegionAssignment::factory()->deputy('2026-10-01', '2026-10-07')->create(['user_id' => $past->id, 'region_id' => $this->est->id]);
        ($this->get)('', $past)->assertOk()->assertJsonPath('opened', true)->assertJsonPath('regions', [])->assertJsonPath('clients_without_order', []);
        expectProblem(($this->get)('?region='.$this->est->id, $past), 404, 'NOT_FOUND');
    });

    it('lists every region of the perimeter, and narrows to the filtered region', function () {
        RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->est->id]);

        $all = ($this->get)()->assertOk();
        expect(collect($all->json('regions'))->pluck('region.code')->all())->toBe(['CENTRE', 'EST'])
            ->and(collect($all->json('clients_without_order'))->pluck('region_id')->unique()->sort()->values()->all())->toBe([$this->centre->id]);

        $only = ($this->get)('?region='.$this->est->id)->assertOk();
        expect(collect($only->json('regions'))->pluck('region.code')->all())->toBe(['EST'])
            ->and(($this->region)($only, 'EST')['orders'])->toBe(['SUBMITTED' => 1, 'VALIDATED' => 0, 'REJECTED' => 0, 'CLOSED' => 0]);
    });
});

describe('content', function () {
    it('gives the region cards of the day, with no data envelope (I-96)', function () {
        $response = ($this->get)()->assertOk();

        expect($response->json())->not->toHaveKey('data')
            ->and(array_keys($response->json()))->toBe(['day', 'opened', 'status', 'cutoffs', 'validation_blocking', 'regions', 'clients_without_order']);
        $response->assertJsonPath('day', '2026-10-08')
            ->assertJsonPath('opened', true)
            ->assertJsonPath('status', 'OPEN')
            ->assertJsonPath('cutoffs', ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00'])
            ->assertJsonPath('validation_blocking', false);
        // Le régional ne voit que sa région.
        expect(collect($response->json('regions'))->pluck('region.code')->all())->toBe(['CENTRE']);

        $day = $this->regionDay->fresh();
        expect(($this->region)($response))->toBe([
            'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
            'status' => 'DISTRIBUTION_PUBLISHED',
            'status_label' => 'Répartition publiée',
            'quota_published_at' => $day->quota_published_at->toIso8601String(),
            'distribution_published_at' => $day->distribution_published_at->toIso8601String(),
            'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00'],
            'extended' => ['distribution' => false, 'ordering' => false, 'validation' => false],
            // A et la deuxième commande de B envoyées ; une validée ; une rejetée ; une annulée par le client.
            'orders' => ['SUBMITTED' => 2, 'VALIDATED' => 1, 'REJECTED' => 1, 'CLOSED' => 1],
            // La plus ancienne commande envoyée : celle de A, à 08:40 (07:40 UTC).
            'oldest_submitted_at' => '2026-10-08T07:40:00+00:00',
            'clients' => ['notified' => 5, 'ordered' => 2, 'without_order' => 3],
        ]);
    });

    it('lists the clients without an order, active only, with their phones, by client code, a rejected or cancelled order not counting (I-88)', function () {
        $response = ($this->get)()->assertOk();

        // Notifiés : A, B, C, G, H (le client bloqué et l'archivé ne le sont pas). Ont commandé : A et B (une commande envoyée ou validée).
        expect($response->json('clients_without_order'))->toBe([
            ['id' => $this->c->id, 'code' => 'C0100', 'name' => 'Client C0100', 'status' => 'ACTIVE', 'region_id' => $this->centre->id, 'phone' => null, 'phone2' => null],
            ['id' => $this->g->id, 'code' => 'C0150', 'name' => 'Client C0150', 'status' => 'ACTIVE', 'region_id' => $this->centre->id, 'phone' => null, 'phone2' => null],
            ['id' => $this->h->id, 'code' => 'C0200', 'name' => 'Client C0200', 'status' => 'ACTIVE', 'region_id' => $this->centre->id, 'phone' => '0770000000', 'phone2' => '0550999999'],
        ]);
    });

    it('counts a client who ordered several times once, and a client of another region never', function () {
        // B a deux commandes comptées (une validée, une envoyée) ; F commande dans l'Est.
        $centre = ($this->region)(($this->get)()->assertOk());

        expect($centre['clients'])->toBe(['notified' => 5, 'ordered' => 2, 'without_order' => 3])
            ->and(Order::query()->where('client_id', $this->b->id)->count())->toBe(2);
    });

    it('follows the decisions: a validation moves an order of tab, a client becomes « with order » once his order is counted', function () {
        ($this->at)('09:30');
        validateOrder($this->orderA);
        // C renvoie une commande après le rejet (I-94) : il a désormais commandé.
        $resent = ($this->submit)($this->c, [[$this->pomme, 1]]);

        $centre = ($this->region)(($this->get)()->assertOk());

        expect($centre['orders'])->toBe(['SUBMITTED' => 2, 'VALIDATED' => 2, 'REJECTED' => 1, 'CLOSED' => 1])
            ->and($centre['clients'])->toBe(['notified' => 5, 'ordered' => 3, 'without_order' => 2])
            // La plus ancienne : la deuxième commande de B (08:50), celle de A est validée.
            ->and($centre['oldest_submitted_at'])->toBe('2026-10-08T07:50:00+00:00');
        $dashboard = ($this->get)()->json();
        expect(collect($dashboard['clients_without_order'])->pluck('code')->all())->toBe(['C0150', 'C0200'])
            ->and($resent->status->value)->toBe('SUBMITTED');
    });

    it('has no oldest submitted order when nothing waits for a decision', function () {
        Order::query()->where('status', 'SUBMITTED')->update(['status' => 'EXPIRED']);

        $centre = ($this->region)(($this->get)()->assertOk());

        expect($centre['oldest_submitted_at'])->toBeNull()
            ->and($centre['orders'])->toBe(['SUBMITTED' => 0, 'VALIDATED' => 1, 'REJECTED' => 1, 'CLOSED' => 3])
            // Une commande expirée ne compte pas : seul B (validée) a commandé.
            ->and($centre['clients'])->toBe(['notified' => 5, 'ordered' => 1, 'without_order' => 4]);
    });

    it('gives the validation cutoff, blocking or not, and the extensions of the region (RG-V08, I-71)', function () {
        app(Settings::class)->update(['cutoffs' => ['validation_blocking' => true]], null);
        $this->regionDay->forceFill([
            'distribution_cutoff_override_at' => CarbonImmutable::parse('2026-10-08 10:30', 'Africa/Algiers')->utc(),
            'ordering_cutoff_override_at' => CarbonImmutable::parse('2026-10-08 13:30', 'Africa/Algiers')->utc(),
            'validation_cutoff_override_at' => CarbonImmutable::parse('2026-10-08 15:30', 'Africa/Algiers')->utc(),
        ])->save();

        $response = ($this->get)()->assertOk();

        $response->assertJsonPath('validation_blocking', true)
            // Les heures de la journée, sans prolongation.
            ->assertJsonPath('cutoffs', ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00']);
        $centre = ($this->region)($response);
        expect($centre['cutoffs'])->toBe(['distribution' => '10:30', 'ordering' => '13:30', 'validation' => '15:30'])
            ->and($centre['extended'])->toBe(['distribution' => true, 'ordering' => true, 'validation' => true]);
    });

    it('does not count the clients before the distribution is sent: notified, ordered and without order stay at 0 for a region without distribution', function () {
        // Région créée après l'ouverture de la journée : en attente des quotas, sans répartition.
        $ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest']);
        RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $ouest->id]);
        Client::factory()->create(['code' => 'C0700', 'region_id' => $ouest->id]);

        $response = ($this->get)()->assertOk();

        $card = ($this->region)($response, 'OUEST');
        expect(collect($response->json('regions'))->pluck('region.code')->all())->toBe(['CENTRE', 'OUEST'])
            ->and($card)->toMatchArray([
                'status' => 'QUOTA_DRAFT',
                'status_label' => 'Quotas en brouillon',
                'quota_published_at' => null,
                'distribution_published_at' => null,
                'orders' => ['SUBMITTED' => 0, 'VALIDATED' => 0, 'REJECTED' => 0, 'CLOSED' => 0],
                'oldest_submitted_at' => null,
                'clients' => ['notified' => 0, 'ordered' => 0, 'without_order' => 0],
            ])
            ->and(collect($response->json('clients_without_order'))->pluck('region_id')->unique()->values()->all())->toBe([$this->centre->id]);
    });

    it('follows the REQUIRED policy of the distribution: only the clients with a borne above 0 were notified (RG-B04)', function () {
        app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);
        $limits = app(LimitBook::class);
        $limits->saveDrafts($this->regionDay, [
            ['client_id' => $this->h->id, 'product_id' => $this->orange->id, 'limit' => 20],
            ['client_id' => $this->c->id, 'product_id' => $this->orange->id, 'limit' => 0],
        ], $this->regional);
        $limits->publish($this->regionDay, $this->regional);

        $centre = ($this->region)(($this->get)()->assertOk());

        // Seul H a une borne positive et n'a pas commandé ; A et B ont commandé avant la politique.
        expect($centre['clients'])->toBe(['notified' => 1, 'ordered' => 2, 'without_order' => 1])
            ->and(collect(($this->get)()->json('clients_without_order'))->pluck('code')->all())->toBe(['C0200']);
    });

    it('reads another day, and answers an empty day for a day not opened', function () {
        $past = ($this->get)('?day=2026-10-20')->assertOk();

        expect($past->json())->toBe([
            'day' => '2026-10-20', 'opened' => false, 'status' => null, 'cutoffs' => null, 'validation_blocking' => false,
            'regions' => [], 'clients_without_order' => [],
        ]);
        // Aujourd'hui par défaut (fuseau métier).
        ($this->get)('')->assertJsonPath('day', '2026-10-08');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 23:30', 'UTC')); // 00:30 le 9 à Alger.
        ($this->get)('')->assertJsonPath('day', '2026-10-09')->assertJsonPath('opened', false);
    });

    it('keeps reading a closed day', function () {
        BusinessDay::query()->update(['status' => 'CLOSED']);
        RegionDay::query()->update(['status' => 'CLOSED']);

        $response = ($this->get)()->assertOk();

        $response->assertJsonPath('opened', true)->assertJsonPath('status', 'CLOSED');
        $centre = ($this->region)($response);
        expect($centre['status'])->toBe('CLOSED')
            ->and($centre['status_label'])->toBe('Clôturée')
            ->and($centre['orders'])->toBe(['SUBMITTED' => 2, 'VALIDATED' => 1, 'REJECTED' => 1, 'CLOSED' => 1]);
    });

    it('reads a bounded number of queries, whatever the number of orders and of clients', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->get)()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries(); // Réchauffe les caches (permissions, paramètres).
        $few = $queries();
        foreach (Client::factory()->count(6)->create(['region_id' => $this->centre->id]) as $client) {
            foreach (['validated', 'rejected'] as $state) {
                Order::factory()->{$state}()->create(['business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $client->id]);
            }
            Order::factory()->create(['business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $client->id]);
        }

        expect($queries())->toBe($few)
            ->and(($this->region)(($this->get)()->assertOk())['clients'])->toBe(['notified' => 11, 'ordered' => 8, 'without_order' => 3]);
    });
});
