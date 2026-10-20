<?php

use App\Domain\Access\Role;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Réconciliation nocturne (§5.8, §9.7, I-93 ; contrat du lot 6, §6) :
// ramymaak:reconcile-quotas compare chaque compteur V(r,p) à la somme des
// quantités validées, journalise l'écart en erreur (code de sortie 1) et les
// dépassements d'invariants en avertissement, et ne corrige jamais.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->accounts = [
        $this->a->id => userWithRole(Role::Client, ['client_id' => $this->a->id]),
        $this->b->id => userWithRole(Role::Client, ['client_id' => $this->b->id]),
    ];
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    // Quotas orange 500 et pomme 300 ; borne de 50 pour A sur l'orange.
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]], [[$this->a, $this->orange, 50]]);
    $this->submit = fn (Client $client, array $lines) => app(ClientOrders::class)->submit(
        $this->accounts[$client->id],
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];
    // V(r,p) réels : orange 40 + 20 = 60, pomme 10 (demandé) puis ajusté à 5.
    $this->orderA = validateOrder(($this->submit)($this->a, [[$this->orange, 40]]));
    $this->orderB = validateOrder(($this->submit)($this->b, [[$this->orange, 20], [$this->pomme, 10]]), [$this->pomme->id => 5]);
    $this->counter = fn (Product $product, ?RegionDay $regionDay = null): ?int => RegionQuota::query()
        ->where('region_day_id', ($regionDay ?? $this->regionDay)->id)->where('product_id', $product->id)->value('validated_qty');
    $this->tamper = fn (Product $product, int $value) => RegionQuota::query()
        ->where('region_day_id', $this->regionDay->id)->where('product_id', $product->id)->update(['validated_qty' => $value]);
    // Compteur incohérent sur une autre journée (sans commande : V = 0 recalculé).
    $this->plant = function (string $day, int $validated): RegionDay {
        $businessDay = BusinessDay::query()->where('day', $day)->first() ?? BusinessDay::factory()->on($day)->create();
        $regionDay = RegionDay::factory()->published()->create(['business_day_id' => $businessDay->id, 'region_id' => $this->centre->id]);
        $quota = RegionQuota::factory()->create(['region_day_id' => $regionDay->id, 'product_id' => $this->orange->id, 'quantity' => 100, 'draft_quantity' => null]);
        RegionQuota::query()->whereKey($quota->id)->update(['validated_qty' => $validated]);

        return $regionDay;
    };
    Log::spy();
});

it('reports nothing and exits 0 when every counter equals the sum of the validated lines', function () {
    expect(($this->counter)($this->orange))->toBe(60)
        ->and(($this->counter)($this->pomme))->toBe(5);

    $this->artisan('ramymaak:reconcile-quotas')
        ->expectsOutputToContain('Quotas réconciliés du 2026-10-08 au 2026-10-08 : aucun écart.')
        ->assertExitCode(0);

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

it('keeps reconciling the counters after the orders move on and after an ADV cancellation gives back the quantities (RG-V07)', function () {
    $adv = userWithRole(Role::Adv);
    app(AdvOrders::class)->transition($adv, OrderStatus::InPreparation, [['id' => $this->orderA->public_id, 'version' => 2]]);
    app(AdvOrders::class)->cancel($adv, $this->orderB, 2, 'RUPTURE', null);

    expect(($this->counter)($this->orange))->toBe(40)
        ->and(($this->counter)($this->pomme))->toBe(0);

    $this->artisan('ramymaak:reconcile-quotas')->expectsOutputToContain('aucun écart')->assertExitCode(0);
    Log::shouldNotHaveReceived('error');
});

it('logs an error and exits 1 for a tampered counter, without correcting it', function () {
    ($this->tamper)($this->orange, 55);
    $audits = AuditLog::query()->count();

    $this->artisan('ramymaak:reconcile-quotas')
        ->expectsOutputToContain('1 écart(s) entre les compteurs et les quantités validées du 2026-10-08 au 2026-10-08 (aucune correction appliquée).')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')->once()->with('quota.reconciliation.mismatch', Mockery::on(fn (array $context) => $context === [
        'from' => '2026-10-08',
        'to' => '2026-10-08',
        'count' => 1,
        'mismatches' => [[
            'business_day' => '2026-10-08',
            'business_day_id' => $this->regionDay->business_day_id,
            'region_id' => $this->centre->id,
            'product_id' => $this->orange->id,
            'counter' => 55,
            'recomputed' => 60,
        ]],
    ]));
    Log::shouldNotHaveReceived('warning');
    // Rapport seul : le compteur reste tel quel, aucune écriture au journal d'audit.
    expect(($this->counter)($this->orange))->toBe(55)
        ->and(($this->counter)($this->pomme))->toBe(5)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('reports every mismatch, including validated lines that have no counter row', function () {
    ($this->tamper)($this->pomme, 9);
    RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $this->orange->id)->delete();

    $this->artisan('ramymaak:reconcile-quotas')
        ->expectsOutputToContain('2 écart(s)')
        ->expectsOutputToContain('absent')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')->once()->with('quota.reconciliation.mismatch', Mockery::on(fn (array $context) => $context['count'] === 2
        && array_map(fn (array $row) => [$row['product_id'], $row['counter'], $row['recomputed']], $context['mismatches']) === [
            [$this->orange->id, null, 60],
            [$this->pomme->id, 9, 5],
        ]));
});

it('logs a client limit breach (I-2) as a warning and keeps exiting 0 when the counters are right', function () {
    // La borne de A sur l'orange passe sous ce qu'il a déjà validé (40).
    ClientLimit::query()->where('client_id', $this->a->id)->where('product_id', $this->orange->id)->update(['max_qty' => 10]);

    $this->artisan('ramymaak:reconcile-quotas')
        ->expectsOutputToContain('1 dépassement(s) d\'invariant (I-1, I-2) : voir le journal.')
        ->assertExitCode(0);

    Log::shouldHaveReceived('warning')->once()->with('quota.reconciliation.breach', Mockery::on(fn (array $context) => $context === [
        'from' => '2026-10-08',
        'to' => '2026-10-08',
        'count' => 1,
        'breaches' => [[
            'invariant' => 'I-2',
            'business_day' => '2026-10-08',
            'region_id' => $this->centre->id,
            'product_id' => $this->orange->id,
            'client_id' => $this->a->id,
            'validated' => 40,
            'ceiling' => 10,
        ]],
    ]));
    Log::shouldNotHaveReceived('error');
});

it('logs a counter above its quota (I-1) as a warning next to the mismatch error', function () {
    ($this->tamper)($this->orange, 520);

    $this->artisan('ramymaak:reconcile-quotas')->assertExitCode(1);

    Log::shouldHaveReceived('warning')->once()->with('quota.reconciliation.breach', Mockery::on(fn (array $context) => $context['count'] === 1
        && $context['breaches'][0]['invariant'] === 'I-1'
        && $context['breaches'][0]['validated'] === 520
        && $context['breaches'][0]['ceiling'] === 500));
    Log::shouldHaveReceived('error')->once()->with('quota.reconciliation.mismatch', Mockery::type('array'));
})->skip(fn () => DB::getDriverName() === 'mysql', 'MySQL refuse V > Q (contrainte CHECK) : I-1 ne peut pas être préparé.');

it('examines by default the 7 latest business days up to today, nothing before and nothing after', function () {
    // Jours ouvrés (dimanche à jeudi) : 7 jusqu'au 08/10 commencent le 30/09.
    foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-09'] as $day) {
        BusinessDay::factory()->on($day)->create();
    }
    ($this->plant)('2026-09-30', 7);
    // Hors période : 8e journée, 9e journée, journée de demain.
    ($this->plant)('2026-09-29', 8);
    ($this->plant)('2026-09-28', 9);
    ($this->plant)('2026-10-09', 11);

    $this->artisan('ramymaak:reconcile-quotas')
        ->expectsOutputToContain('1 écart(s) entre les compteurs et les quantités validées du 2026-09-30 au 2026-10-08')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')->once()->with('quota.reconciliation.mismatch', Mockery::on(fn (array $context) => $context['from'] === '2026-09-30'
        && $context['to'] === '2026-10-08'
        && $context['count'] === 1
        && $context['mismatches'][0]['business_day'] === '2026-09-30'
        && $context['mismatches'][0]['counter'] === 7
        && $context['mismatches'][0]['recomputed'] === 0));
});

it('examines the business days it has when there are fewer than 7, and the day itself when there is none before it', function () {
    $this->artisan('ramymaak:reconcile-quotas')->expectsOutputToContain('Quotas réconciliés du 2026-10-08 au 2026-10-08 : aucun écart.')->assertExitCode(0);

    BusinessDay::factory()->on('2026-10-04')->create();
    ($this->plant)('2026-10-04', 3);
    $this->artisan('ramymaak:reconcile-quotas')
        ->expectsOutputToContain('1 écart(s) entre les compteurs et les quantités validées du 2026-10-04 au 2026-10-08')
        ->assertExitCode(1);

    // Aucune journée jusqu'à la date demandée : cette date seule.
    $this->artisan('ramymaak:reconcile-quotas', ['--to' => '2026-09-01'])
        ->expectsOutputToContain('Quotas réconciliés du 2026-09-01 au 2026-09-01 : aucun écart.')
        ->assertExitCode(0);
});

it('takes the period from --from and --to, bounds included', function () {
    ($this->plant)('2026-10-04', 3);
    ($this->plant)('2026-10-05', 4);
    ($this->plant)('2026-10-06', 5);

    $this->artisan('ramymaak:reconcile-quotas', ['--from' => '2026-10-05', '--to' => '2026-10-06'])
        ->expectsOutputToContain('2 écart(s) entre les compteurs et les quantités validées du 2026-10-05 au 2026-10-06')
        ->assertExitCode(1);
    Log::shouldHaveReceived('error')->once()->with('quota.reconciliation.mismatch', Mockery::on(fn (array $context) => $context['from'] === '2026-10-05'
        && $context['to'] === '2026-10-06'
        && array_column($context['mismatches'], 'business_day') === ['2026-10-05', '2026-10-06']));

    // --from seul : jusqu'à aujourd'hui ; --to seul : les 7 dernières journées jusqu'à cette date (aucune ici).
    $this->artisan('ramymaak:reconcile-quotas', ['--from' => '2026-10-06'])
        ->expectsOutputToContain('1 écart(s) entre les compteurs et les quantités validées du 2026-10-06 au 2026-10-08')
        ->assertExitCode(1);
    $this->artisan('ramymaak:reconcile-quotas', ['--to' => '2026-10-03'])
        ->expectsOutputToContain('Quotas réconciliés du 2026-10-03 au 2026-10-03 : aucun écart.')
        ->assertExitCode(0);
    // Journée sans écart : le jour des commandes réelles, seul.
    $this->artisan('ramymaak:reconcile-quotas', ['--from' => '2026-10-08', '--to' => '2026-10-08'])->assertExitCode(0);
});

it('refuses an invalid date and an inverted period, without examining anything', function () {
    ($this->tamper)($this->orange, 55);

    $this->artisan('ramymaak:reconcile-quotas', ['--from' => '2026-13-45'])
        ->expectsOutputToContain('--from : date invalide « 2026-13-45 » (AAAA-MM-JJ attendu).')
        ->assertExitCode(2);
    $this->artisan('ramymaak:reconcile-quotas', ['--to' => 'demain'])
        ->expectsOutputToContain('--to : date invalide « demain » (AAAA-MM-JJ attendu).')
        ->assertExitCode(2);
    $this->artisan('ramymaak:reconcile-quotas', ['--from' => '2026-10-09', '--to' => '2026-10-08'])
        ->expectsOutputToContain('La première journée est postérieure à la dernière.')
        ->assertExitCode(2);

    Log::shouldNotHaveReceived('error');
});

it('caps the detail logged at 100 lines and still gives the total', function () {
    foreach (range(1, 105) as $number) {
        $product = Product::factory()->create(['sku' => sprintf('X%04d', $number)]);
        $quota = RegionQuota::factory()->create(['region_day_id' => $this->regionDay->id, 'product_id' => $product->id, 'quantity' => 500, 'draft_quantity' => null]);
        RegionQuota::query()->whereKey($quota->id)->update(['validated_qty' => $number]);
    }

    $this->artisan('ramymaak:reconcile-quotas')->expectsOutputToContain('105 écart(s)')->assertExitCode(1);

    Log::shouldHaveReceived('error')->once()->with('quota.reconciliation.mismatch', Mockery::on(fn (array $context) => $context['count'] === 105
        && count($context['mismatches']) === 100));
});

it('is scheduled every night at 02:10, Algiers time, without overlapping', function () {
    app(Kernel::class)->bootstrap();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'ramymaak:reconcile-quotas'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('10 2 * * *')
        ->and($events->first()->timezone)->toBe('Africa/Algiers')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        // Verrou court : un arrêt brutal du planificateur ne bloque pas la tâche 24 h.
        ->and($events->first()->expiresAt)->toBe(30);
});
