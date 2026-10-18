<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\ClientOrders;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Referentials\ProductPhotos;
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
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Journée du client : GET /client/today[?day=] (EF-CLT-01, EF-CLT-02 ;
// contrat du lot 5, §3) — état de la journée (I-43), envoi possible ou
// motif, commandes du jour, catalogue du jour avec borne et disponible du
// client, jamais Q, R, ΣB ni les bornes des autres clients (RG-C09).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00, livraison le dimanche 11.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
    ]);
    Storage::fake('media', config('filesystems.disks.media'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->account = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);

    $this->juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 10]);
    $this->milk = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 5]);
    $product = fn (string $sku, string $name, ProductFamily $family, int $sort, array $extra = []) => Product::factory()->create([
        'sku' => $sku, 'name' => $name, 'short_name' => Str::after($name, 'Jus '), 'family_id' => $family->id, 'sort_order' => $sort,
        'format' => '1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'units_per_sales_unit' => 6, 'vat_rate' => '19.00', ...$extra,
    ]);
    $this->orange = $product('P0042', 'Jus orange 1 L', $this->juices, 2, ['is_featured' => true, 'ean' => '6130000000427']);
    $this->pomme = $product('P0057', 'Jus pomme 1 L', $this->juices, 1, ['is_new' => true]);
    $this->fraise = $product('P0080', 'Jus fraise 1 L', $this->juices, 3);
    $this->mangue = $product('P0090', 'Jus mangue 1 L', $this->juices, 4);
    $this->lben = $product('P0103', 'Lben 1 L', $this->milk, 1, ['short_name' => 'Lben', 'units_per_sales_unit' => 1, 'sales_unit_label' => 'Bouteille 1 L', 'vat_rate' => '9.00']);
    $this->kiwi = $product('P0110', 'Jus kiwi 1 L', $this->juices, 5);
    $price = fn (Product $product, string $amount, string $from) => ProductPrice::factory()->create(['product_id' => $product->id, 'price_ht' => $amount, 'valid_from' => $from]);
    // Orange : prix du jour 900,00 depuis le 1er octobre, prochain prix programmé (jamais montré au client).
    $price($this->orange, '800.00', '2026-01-01');
    $price($this->orange, '900.00', '2026-10-01');
    $price($this->orange, '990.00', '2026-11-01');
    $price($this->pomme, '850.50', '2026-01-01');
    $price($this->lben, '120.00', '2026-01-01');

    // Orange : borne 50 (B en a 100) ; pomme et lben sans borne (LIBRE) ; fraise interdite (0) ;
    // mangue bornée à 20, déjà toute commandée ; kiwi sans prix (I-44).
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [
        [$this->orange, 500], [$this->pomme, 300], [$this->fraise, 100], [$this->mangue, 200], [$this->lben, 800], [$this->kiwi, 100],
    ], [
        [$this->a, $this->orange, 50], [$this->b, $this->orange, 100], [$this->a, $this->fraise, 0], [$this->a, $this->mangue, 20],
    ]);
    $this->kiwi->prices()->delete();
    // Lben : tout le quota de la région est validé (R = 0).
    RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $this->lben->id)->update(['validated_qty' => 800]);

    $this->photos = fn (Product $product, int $count) => collect(range(1, $count))
        ->map(fn (int $index) => app(ProductPhotos::class)->add($product, UploadedFile::fake()->image("photo{$index}.jpg", 40, 30)))
        ->all();
    $this->submit = fn (User $by, array $lines, string $day = '2026-10-08') => app(ClientOrders::class)->submit(
        $by,
        (string) Str::uuid(),
        BusinessClock::parseDay($day),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];
    $this->summary = fn (Order $order, bool $canModify, bool $canCancel) => [
        'id' => $order->public_id, 'number' => $order->number, 'day' => $order->businessDay->dayKey(),
        'status' => $order->status->value, 'status_label' => $order->status->label(), 'version' => $order->version,
        'submitted_at' => $order->submitted_at->toIso8601String(),
        // Montant tel que le relit json_decode : 9000 pour 9 000,00, 141.75 pour 141,75.
        'total_ht' => json_decode(json_encode((float) $order->total_ht)), 'total_ttc' => json_decode(json_encode((float) $order->total_ttc)),
        'lines_count' => $order->lines()->count(), 'is_adjusted' => false,
        'can_modify' => $canModify, 'can_cancel' => $canCancel,
    ];
    $this->today = fn (string $query = '') => $this->actingAs($this->account)->getJson('/api/v1/client/today'.$query);
});

it('returns the day, the client, its orders and the catalogue of the day (RG-C09, I-44)', function () {
    app(Settings::class)->update(['orders' => ['max_per_day' => 2]], null);
    [$main, $second] = ($this->photos)($this->orange, 2);
    $order = ($this->submit)($this->account, [[$this->orange, 10], [$this->mangue, 20]]);
    ($this->submit)($this->accountB, [[$this->orange, 30]]);
    $photo = fn (Media $media, bool $isMain) => [
        'id' => $media->id,
        'is_main' => $isMain,
        'urls' => [
            'thumb' => "/media/{$media->id}/conversions/p0042-thumb.webp",
            'medium' => "/media/{$media->id}/conversions/p0042-medium.webp",
            'large' => "/media/{$media->id}/conversions/p0042-large.webp",
        ],
    ];
    $fiche = fn (Product $product) => [
        'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'short_name' => $product->short_name,
        'family_id' => $product->family_id, 'format' => '1 L', 'sales_unit_label' => $product->sales_unit_label,
        'units_per_sales_unit' => $product->units_per_sales_unit, 'vat_rate' => (float) $product->vat_rate,
    ];

    ($this->today)()->assertOk()->assertExactJson([
        'server_time' => '2026-10-08T08:30:00+00:00',
        'day' => '2026-10-08',
        'delivery_date' => '2026-10-11',
        'status' => 'ORDERING_OPEN',
        'ordering_cutoff' => '13:00',
        'ordering_cutoff_at' => '2026-10-08T12:00:00+00:00',
        'ordering_cutoff_extended' => false,
        'client' => ['id' => $this->a->id, 'code' => 'C0012', 'name' => 'Superette El Baraka', 'status' => 'ACTIVE'],
        'max_orders_per_day' => 2,
        'min_amount_ht' => null,
        'can_submit' => true,
        'submit_blocker' => null,
        // 10 × 900 + 20 × 900 = 27 000 HT, TVA 19 % : 32 130 TTC.
        'orders' => [[...($this->summary)($order, true, true), 'total_ht' => 27000.0, 'total_ttc' => 32130.0, 'lines_count' => 2]],
        // Familles des produits listés, par ordre puis code.
        'families' => [
            ['id' => $this->milk->id, 'code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 5],
            ['id' => $this->juices->id, 'code' => 'JUS', 'name' => 'Jus', 'sort_order' => 10],
        ],
        // Famille (ordre, code), puis ordre et code article ; ni la fraise (borne 0) ni le kiwi (sans prix).
        'products' => [
            [
                ...$fiche($this->lben), 'format' => '1 L',
                'price' => ['price_ht' => 120.0, 'price_ttc' => 130.8, 'unit_price_ht' => 120.0, 'valid_from' => '2026-01-01'],
                'photos' => [], 'is_new' => false, 'is_featured' => false,
                // Sans borne, reste région nul : épuisé, sans quantité montrée.
                'limit' => null, 'engaged' => 0, 'available' => null, 'sold_out' => true,
            ],
            [
                ...$fiche($this->pomme),
                // 850,50 × 19 % = 161,595 → 161,60 ; prix unitaire 850,50 / 6 = 141,75.
                'price' => ['price_ht' => 850.5, 'price_ttc' => 1012.1, 'unit_price_ht' => 141.75, 'valid_from' => '2026-01-01'],
                'photos' => [], 'is_new' => true, 'is_featured' => false,
                'limit' => null, 'engaged' => 0, 'available' => null, 'sold_out' => false,
            ],
            [
                ...$fiche($this->orange),
                'price' => ['price_ht' => 900.0, 'price_ttc' => 1071.0, 'unit_price_ht' => 150.0, 'valid_from' => '2026-10-01'],
                'photos' => [$photo($main, true), $photo($second, false)], 'is_new' => false, 'is_featured' => true,
                // D = B − E = 50 − 10 ; les 30 de B ne comptent pas.
                'limit' => 50, 'engaged' => 10, 'available' => 40, 'sold_out' => false,
            ],
            [
                ...$fiche($this->mangue),
                'price' => ['price_ht' => 900.0, 'price_ttc' => 1071.0, 'unit_price_ht' => 150.0, 'valid_from' => '2026-01-01'],
                'photos' => [], 'is_new' => false, 'is_featured' => false,
                'limit' => 20, 'engaged' => 20, 'available' => 0, 'sold_out' => true,
            ],
        ],
    ]);
});

it('never reveals the quotas, the region remaining, the other limits or the back-office fields (RG-C09)', function () {
    ($this->photos)($this->orange, 1);
    ($this->submit)($this->accountB, [[$this->orange, 30]]);

    $response = ($this->today)()->assertOk();
    $keys = [];
    $values = [];
    $walk = function (mixed $value) use (&$walk, &$keys, &$values): void {
        if (! is_array($value)) {
            $values[] = $value;

            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[$key] = true;
            }
            $walk($item);
        }
    };
    $walk($response->json());

    $forbidden = ['quota', 'quantity', 'region_validated', 'region_remaining', 'remaining', 'sum_limits', 'validated', 'pending',
        'price_ht', 'next_price', 'ean', 'pending_deactivation_on', 'sales_units_per_pallet', 'is_active', 'cells', 'limits'];
    // `price_ht` n'apparaît que dans le PriceRef du produit.
    expect(array_values(array_intersect(array_keys($keys), $forbidden)))->toBe(['price_ht'])
        ->and(collect($response->json('products'))->every(fn (array $product) => ! array_key_exists('price_ht', $product)))->toBeTrue()
        ->and(array_keys($response->json('products.0')))->toBe([
            'id', 'sku', 'name', 'short_name', 'family_id', 'format', 'sales_unit_label', 'units_per_sales_unit', 'vat_rate',
            'price', 'photos', 'is_new', 'is_featured', 'limit', 'engaged', 'available', 'sold_out',
        ])
        // Borne du client (50), pas celle de B (100) ; sans borne, ni borne ni disponible.
        ->and(collect($response->json('products'))->pluck('limit', 'sku')->all())->toBe(['P0103' => null, 'P0057' => null, 'P0042' => 50, 'P0090' => 20])
        ->and(collect($response->json('products'))->pluck('available', 'sku')->all())->toBe(['P0103' => null, 'P0057' => null, 'P0042' => 50, 'P0090' => 20])
        // Ni l'EAN, ni le prochain prix (990), ni les quotas et restes (500, 300, 200, 800), ni la borne de B (100).
        ->and($values)->not->toContain('6130000000427')
        ->and(array_values(array_intersect($values, [990, 500, 300, 200, 800, 100])))->toBe([]);
});

it('lists only the products with a limit under the REQUIRED policy (RG-B04)', function () {
    app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);

    ($this->today)()->assertOk()
        ->assertJsonPath('products.*.sku', ['P0042', 'P0090'])
        ->assertJsonPath('families.*.code', ['JUS']);
});

it('waits for the quotas while the day is not opened', function () {
    ($this->today)('?day=2026-10-11')->assertOk()->assertExactJson([
        'server_time' => '2026-10-08T08:30:00+00:00',
        'day' => '2026-10-11',
        'delivery_date' => null,
        'status' => 'WAITING_QUOTAS',
        'ordering_cutoff' => null,
        'ordering_cutoff_at' => null,
        'ordering_cutoff_extended' => false,
        'client' => ['id' => $this->a->id, 'code' => 'C0012', 'name' => 'Superette El Baraka', 'status' => 'ACTIVE'],
        'max_orders_per_day' => 1,
        'min_amount_ht' => null,
        'can_submit' => false,
        'submit_blocker' => 'DAY_NOT_OPEN',
        'orders' => [],
        'families' => [],
        'products' => [],
    ]);
});

it('waits for the quotas, without catalogue, until the distribution is sent', function () {
    $adv = userWithRole(Role::Adv);
    $day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-11'), $adv);
    $quotas = app(QuotaBook::class);
    $quotas->saveDrafts($day, [['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 100]], $adv);
    $quotas->publish($day, [$this->centre->id], $adv);

    ($this->today)('?day=2026-10-11')->assertOk()
        ->assertJsonPath('status', 'WAITING_QUOTAS')
        ->assertJsonPath('delivery_date', $day->delivery_date->format('Y-m-d'))
        ->assertJsonPath('ordering_cutoff', '13:00')
        ->assertJsonPath('ordering_cutoff_at', '2026-10-11T12:00:00+00:00')
        ->assertJsonPath('can_submit', false)
        ->assertJsonPath('submit_blocker', 'DAY_NOT_OPEN')
        ->assertJsonPath('products', [])
        ->assertJsonPath('families', []);

    // Heure limite passée sans répartition envoyée : commandes clôturées, toujours sans catalogue.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 13:00', 'Africa/Algiers'));
    ($this->today)('?day=2026-10-11')->assertOk()
        ->assertJsonPath('status', 'ORDERING_CLOSED')
        ->assertJsonPath('submit_blocker', 'CUTOFF_PASSED')
        ->assertJsonPath('products', []);
});

it('closes the ordering at the cutoff: catalogue readable, orders no longer modifiable (RG-C01, RG-C05)', function () {
    $order = ($this->submit)($this->account, [[$this->orange, 10]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'Africa/Algiers'));

    ($this->today)()->assertOk()
        ->assertJsonPath('day', '2026-10-08')
        ->assertJsonPath('status', 'ORDERING_CLOSED')
        ->assertJsonPath('server_time', '2026-10-08T12:00:00+00:00')
        ->assertJsonPath('can_submit', false)
        ->assertJsonPath('submit_blocker', 'CUTOFF_PASSED')
        ->assertJsonPath('orders', [($this->summary)($order, false, false)])
        ->assertJsonPath('products.*.sku', ['P0103', 'P0057', 'P0042', 'P0090'])
        ->assertJsonPath('products.2.available', 40);
});

it('extends the cutoff of the region of the client (RG-A05)', function () {
    $order = ($this->submit)($this->account, [[$this->orange, 10]]);
    app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Ordering, '13:30', userWithRole(Role::Adv));
    $this->travelTo(CarbonImmutable::parse('2026-10-08 13:10', 'Africa/Algiers'));

    ($this->today)()->assertOk()
        ->assertJsonPath('status', 'ORDERING_OPEN')
        ->assertJsonPath('ordering_cutoff', '13:30')
        ->assertJsonPath('ordering_cutoff_at', '2026-10-08T12:30:00+00:00')
        ->assertJsonPath('ordering_cutoff_extended', true)
        ->assertJsonPath('submit_blocker', 'MAX_ORDERS_REACHED')
        ->assertJsonPath('orders', [($this->summary)($order, true, true)]);
});

it('shows a closed day with its expired orders and without catalogue', function () {
    ($this->submit)($this->account, [[$this->orange, 10]]);
    app(DayCalendar::class)->close($this->regionDay->businessDay);

    $response = ($this->today)()->assertOk()
        ->assertJsonPath('day', '2026-10-08')
        ->assertJsonPath('status', 'DAY_CLOSED')
        ->assertJsonPath('delivery_date', '2026-10-11')
        ->assertJsonPath('ordering_cutoff', '13:00')
        ->assertJsonPath('can_submit', false)
        ->assertJsonPath('submit_blocker', 'DAY_NOT_OPEN')
        ->assertJsonPath('products', [])
        ->assertJsonPath('families', []);
    expect($response->json('orders'))->toHaveCount(1)
        ->and($response->json('orders.0'))->toMatchArray(['status' => 'EXPIRED', 'status_label' => 'Expirée', 'version' => 2, 'can_modify' => false, 'can_cancel' => false]);
});

it('tells a blocked client that it cannot order, and lets it cancel (I-53)', function () {
    $order = ($this->submit)($this->account, [[$this->orange, 10]]);
    $this->a->forceFill(['status' => 'BLOCKED'])->save();

    ($this->today)()->assertOk()
        ->assertJsonPath('status', 'ORDERING_OPEN')
        ->assertJsonPath('client.status', 'BLOCKED')
        ->assertJsonPath('can_submit', false)
        ->assertJsonPath('submit_blocker', 'CLIENT_BLOCKED')
        ->assertJsonPath('orders', [($this->summary)($order, false, true)])
        ->assertJsonPath('products.*.sku', ['P0103', 'P0057', 'P0042', 'P0090']);

    // Le blocage passe avant tout autre motif.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 14:00', 'Africa/Algiers'));
    ($this->today)()->assertJsonPath('submit_blocker', 'CLIENT_BLOCKED');
    ($this->today)('?day=2026-10-11')->assertJsonPath('submit_blocker', 'CLIENT_BLOCKED');
});

it('counts the orders of the day against the maximum, without the cancelled ones, all statuses listed newest first (RG-C04)', function () {
    $first = ($this->submit)($this->account, [[$this->orange, 10]]);
    ($this->today)()->assertJsonPath('can_submit', false)
        ->assertJsonPath('submit_blocker', 'MAX_ORDERS_REACHED')
        ->assertJsonPath('max_orders_per_day', 1);

    app(ClientOrders::class)->cancel($this->account, $first->public_id, 1);
    ($this->today)()->assertJsonPath('can_submit', true)->assertJsonPath('submit_blocker', null);

    $second = ($this->submit)($this->account, [[$this->pomme, 3]]);
    ($this->today)()->assertJsonPath('submit_blocker', 'MAX_ORDERS_REACHED')
        ->assertJsonPath('orders', [($this->summary)($second, true, true), ($this->summary)($first->fresh(), false, false)])
        ->assertJsonPath('orders.1.status', 'CANCELLED');
});

it('gives the minimum amount when it is set (RG-C06)', function () {
    app(Settings::class)->update(['orders' => ['min_amount' => 15000.5]], null);

    ($this->today)()->assertJsonPath('min_amount_ht', 15000.5);
});

it('proposes the first day open for ordering, from today on (I-42)', function () {
    // Après l'heure limite : aujourd'hui, commandes clôturées, faute de mieux.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Africa/Algiers'));
    ($this->today)()->assertJsonPath('day', '2026-10-08')->assertJsonPath('status', 'ORDERING_CLOSED');

    // Répartition du dimanche 11 envoyée la veille : journée proposée.
    openOrderingDay('2026-10-11', $this->centre, [[$this->orange, 100]], [[$this->a, $this->orange, 30]]);
    ($this->today)()->assertOk()
        ->assertJsonPath('day', '2026-10-11')
        ->assertJsonPath('status', 'ORDERING_OPEN')
        ->assertJsonPath('ordering_cutoff_at', '2026-10-11T12:00:00+00:00')
        ->assertJsonPath('can_submit', true)
        ->assertJsonPath('products.*.sku', ['P0042'])
        ->assertJsonPath('products.0.limit', 30);

    // Aujourd'hui clôturé : toujours le 11 ; le 8 reste lisible sur demande.
    app(DayCalendar::class)->close($this->regionDay->businessDay);
    ($this->today)()->assertJsonPath('day', '2026-10-11');
    ($this->today)('?day=2026-10-08')->assertJsonPath('day', '2026-10-08')->assertJsonPath('status', 'DAY_CLOSED');
});

it('answers 422 to an invalid day', function (string $query) {
    expectProblem(($this->today)($query), 422, 'VALIDATION_FAILED');
    ($this->today)($query)->assertJsonStructure(['errors' => ['day']]);
})->with([
    'format français' => ['?day=08/10/2026'],
    'mois 13' => ['?day=2026-13-01'],
    '31 février' => ['?day=2026-02-31'],
    'texte' => ['?day=demain'],
    'tableau' => ['?day[]=2026-10-08'],
]);

it('loads the day with a number of queries that does not depend on the number of products', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->today)('?day=2026-10-12')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // Journée du lundi 12 : produits bornés, avec photo, et une commande.
    $catalogue = function (int $count): void {
        $family = ProductFamily::factory()->create();
        $products = Product::factory()->count($count)->create(['family_id' => $family->id]);
        $products->each(fn (Product $product) => ($this->photos)($product, 1));
        $regionDay = openOrderingDay('2026-10-12', $this->centre, $products->map(fn (Product $p) => [$p, 100])->all(),
            $products->map(fn (Product $p) => [$this->a, $p, 10])->all());
        if (! Order::query()->where('client_id', $this->a->id)->where('business_day_id', $regionDay->business_day_id)->exists()) {
            ($this->submit)($this->account, [[$products->first(), 1]], '2026-10-12');
        }
    };

    $catalogue(3);
    $queries(); // Réchauffe les caches (permissions, paramètres).
    $few = $queries();
    $catalogue(27);
    $queries();
    $many = $queries();

    expect($many)->toBe($few)
        ->and(($this->today)('?day=2026-10-12')->json('products'))->toHaveCount(30);
});
