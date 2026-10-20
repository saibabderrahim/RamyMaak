<?php

use App\Domain\Orders\OrderStatus;
use App\Domain\Quotas\QuotaExceeded;
use App\Domain\Quotas\QuotaLedger;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Registre des validations (§5.8, §9.4, ENF-15) : seul écrivain de V(r,p),
// réservation conditionnelle, restitution gardée, réconciliation en lecture.

beforeEach(function () {
    $this->ledger = app(QuotaLedger::class);
    $this->centre = Region::factory()->create();
    $this->day = BusinessDay::factory()->on('2026-10-08')->create();
    $this->regionDay = RegionDay::factory()->published()->create(['business_day_id' => $this->day->id, 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create();
    $this->pomme = Product::factory()->create();
    $this->lben = Product::factory()->create();
    $this->quota = function (Product $product, int $quantity, int $validated = 0, ?RegionDay $regionDay = null): RegionQuota {
        $quota = RegionQuota::factory()->create([
            'region_day_id' => ($regionDay ?? $this->regionDay)->id, 'product_id' => $product->id, 'quantity' => $quantity, 'draft_quantity' => null,
        ]);
        RegionQuota::query()->whereKey($quota->id)->update(['validated_qty' => $validated]);

        return $quota;
    };
    $this->counter = fn (Product $product): int => RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $product->id)->value('validated_qty');
});

it('locks the counters of the products, by product, and skips the products without quota', function () {
    ($this->quota)($this->pomme, 300, 10);
    ($this->quota)($this->orange, 100, 40);

    $counters = $this->ledger->lock($this->regionDay->id, [$this->pomme->id, $this->lben->id, $this->orange->id, $this->orange->id]);

    expect($counters->keys()->all())->toBe([$this->orange->id, $this->pomme->id])
        ->and($counters->get($this->orange->id)->only(['quantity', 'validated_qty']))->toBe(['quantity' => 100, 'validated_qty' => 40])
        ->and($this->ledger->lock($this->regionDay->id, []))->toBeEmpty();
});

it('reserves only under the quota, never touching the counter for a quantity 0 (§5.8)', function () {
    ($this->quota)($this->orange, 100, 60);
    ($this->quota)($this->pomme, 300, 0);

    $this->ledger->reserve($this->regionDay->id, [$this->pomme->id => 0, $this->orange->id => 40]);
    expect(($this->counter)($this->orange))->toBe(100)->and(($this->counter)($this->pomme))->toBe(0);

    // V + q > Q, ou pas de ligne de quota : refusé, compteur inchangé.
    expect(fn () => $this->ledger->reserve($this->regionDay->id, [$this->orange->id => 1]))
        ->toThrow(fn (QuotaExceeded $e) => expect([$e->regionDayId, $e->productId, $e->quantity])->toBe([$this->regionDay->id, $this->orange->id, 1]));
    expect(fn () => $this->ledger->reserve($this->regionDay->id, [$this->lben->id => 1]))->toThrow(QuotaExceeded::class);
    expect(($this->counter)($this->orange))->toBe(100);
});

it('gives back only what the counter holds, never clamping silently (H-06)', function () {
    ($this->quota)($this->orange, 100, 40);
    ($this->quota)($this->pomme, 300, 5);

    $this->ledger->release($this->regionDay->id, [$this->orange->id => 15, $this->pomme->id => 0]);
    expect(($this->counter)($this->orange))->toBe(25)->and(($this->counter)($this->pomme))->toBe(5);

    expect(fn () => $this->ledger->release($this->regionDay->id, [$this->pomme->id => 6]))
        ->toThrow(LogicException::class, "Compteur validé incohérent : journée régionale {$this->regionDay->id}, produit {$this->pomme->id}, restitution de 6.");
    expect(($this->counter)($this->pomme))->toBe(5);
});

it('reconciles every counter of the period with the validated lines, read-only (§5.8, I-93)', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    $order = fn (OrderStatus $status, array $lines, ?BusinessDay $day = null) => Order::factory()->status($status)->withLines($lines)->create([
        'client_id' => $client->id, 'region_id' => $this->centre->id, 'business_day_id' => ($day ?? $this->day)->id,
    ]);
    ($this->quota)($this->orange, 100, 50);   // 30 + 20 : juste
    ($this->quota)($this->pomme, 300, 12);    // 10 seulement : écart
    ($this->quota)($this->lben, 50, 0);       // rien de validé : juste
    $order(OrderStatus::Validated, [[$this->orange, 30, 30], [$this->pomme, 10, 10]]);
    $order(OrderStatus::Shipped, [[$this->orange, 25, 20]]);
    // Annulée après validation, rejetée, envoyée : ne comptent pas.
    $order(OrderStatus::Cancelled, [[$this->orange, 99, 99]]);
    $order(OrderStatus::Submitted, [[$this->lben, 9]]);
    // Ligne validée sans compteur (produit sans ligne de quota).
    $orphan = Product::factory()->create();
    $order(OrderStatus::InPreparation, [[$orphan, 4, 4]]);
    // Autre journée, hors période.
    $later = BusinessDay::factory()->on('2026-10-20')->create();
    $laterDay = RegionDay::factory()->published()->create(['business_day_id' => $later->id, 'region_id' => $this->centre->id]);
    ($this->quota)($this->orange, 100, 7, $laterDay);

    $mismatches = $this->ledger->reconcile(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-08'));

    expect($mismatches)->toBe([
        ['business_day' => '2026-10-08', 'business_day_id' => $this->day->id, 'region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'counter' => 12, 'recomputed' => 10],
        ['business_day' => '2026-10-08', 'business_day_id' => $this->day->id, 'region_id' => $this->centre->id, 'product_id' => $orphan->id, 'counter' => null, 'recomputed' => 4],
    ])
        ->and(($this->counter)($this->pomme))->toBe(12)
        ->and($this->ledger->reconcile(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-20')))->toHaveCount(1);
});

it('reports the breaches of I-1 and I-2 as warnings (§5.8, I-93)', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    ($this->quota)($this->orange, 100, 120);
    ($this->quota)($this->pomme, 300, 30);
    ClientLimit::factory()->published(25)->create(['region_day_id' => $this->regionDay->id, 'client_id' => $client->id, 'product_id' => $this->pomme->id]);
    Order::factory()->validated()->withLines([[$this->pomme, 30, 30]])->create(['client_id' => $client->id, 'region_id' => $this->centre->id, 'business_day_id' => $this->day->id]);

    expect($this->ledger->breaches(CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-08')))->toBe([
        ['invariant' => 'I-1', 'business_day' => '2026-10-08', 'region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'client_id' => null, 'validated' => 120, 'ceiling' => 100],
        ['invariant' => 'I-2', 'business_day' => '2026-10-08', 'region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'client_id' => $client->id, 'validated' => 30, 'ceiling' => 25],
    ]);
})->skip(fn () => DB::getDriverName() === 'mysql', 'MySQL refuse V > Q (contrainte CHECK) : I-1 ne peut pas être préparé.');
