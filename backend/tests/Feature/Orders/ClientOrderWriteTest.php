<?php

use App\Domain\Access\Role;
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
use App\Http\Problems\ErrorCode;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\RegionDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Envoi, modification et annulation par le client : POST /client/orders,
// PATCH /client/orders/{id}, POST /client/orders/{id}/cancel (contrat du
// lot 5, §3) — format (422), renvoi idempotent (200, RG-C07), refus métier
// de ClientOrders dans l'ordre du contrat, en Problem Details avec leurs
// membres (violations, max_orders_per_day, min_amount_ht, version).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
    ]);
    Storage::fake('media', config('filesystems.disks.media'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->account = userWithRole(Role::Client, ['client_id' => $this->a->id, 'name' => 'Gérant C0012']);
    $this->second = userWithRole(Role::Client, ['client_id' => $this->a->id, 'name' => 'Vendeur C0012']);
    $this->orange = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'vat_rate' => '19.00',
    ]);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'vat_rate' => '19.00']);
    $this->lben = Product::factory()->create(['sku' => 'P0103', 'name' => 'Lben 1 L', 'vat_rate' => '9.00']);
    $this->fraise = Product::factory()->create(['sku' => 'P0080']);
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '900.00', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->pomme->id, 'price_ht' => '850.50', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->lben->id, 'price_ht' => '120.00', 'valid_from' => '2026-01-01']);
    // Orange bornée à 50 ; pomme et lben sans borne (LIBRE) ; fraise interdite (borne 0).
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300], [$this->lben, 800], [$this->fraise, 100]], [
        [$this->a, $this->orange, 50], [$this->a, $this->fraise, 0],
    ]);

    $this->lines = fn (array $lines) => array_map(fn (array $line) => ['product_id' => $line[0] instanceof Product ? $line[0]->id : $line[0], 'quantity' => $line[1]], $lines);
    $this->body = fn (array $overrides = []) => [
        'client_request_id' => (string) Str::uuid(),
        'day' => '2026-10-08',
        'comment' => null,
        'lines' => ($this->lines)([[$this->orange, 40], [$this->pomme, 3]]),
        ...$overrides,
    ];
    $this->store = fn (array $body) => $this->actingAs($this->account)->postJson('/api/v1/client/orders', $body);
    $this->patch = fn (Order $order, array $body) => $this->actingAs($this->account)->patchJson("/api/v1/client/orders/{$order->public_id}", $body);
    $this->cancel = fn (Order $order, array $body) => $this->actingAs($this->account)->postJson("/api/v1/client/orders/{$order->public_id}/cancel", $body);
    // Commande envoyée par l'API (orange 40, pomme 3 par défaut).
    $this->order = function (array $overrides = []): Order {
        $id = ($this->store)(($this->body)($overrides))->assertCreated()->json('id');

        return Order::query()->where('public_id', $id)->sole();
    };
    $this->problem = fn (ErrorCode $code, string $detail, array $extra = []) => [
        'type' => $code->type(), 'title' => $code->title(), 'status' => $code->status(), 'code' => $code->value, 'detail' => $detail, ...$extra,
    ];
});

describe('POST /client/orders', function () {
    it('creates the order and answers 201 with the full order (RG-C01 à RG-C03, RG-P03, RG-P04)', function () {
        $photo = app(ProductPhotos::class)->add($this->orange, UploadedFile::fake()->image('orange.jpg', 40, 30));
        $id = (string) Str::uuid();

        $response = ($this->store)(($this->body)(['client_request_id' => $id, 'comment' => '  Livraison avant 10 h  ']))->assertCreated();
        $order = Order::query()->sole();

        $response->assertExactJson([
            'id' => $order->public_id,
            'number' => 'RMK-261008-00001',
            'day' => '2026-10-08',
            'status' => 'SUBMITTED',
            'status_label' => 'Envoyée',
            'version' => 1,
            'submitted_at' => '2026-10-08T08:30:00+00:00',
            // HT : 36 000 + 2 551,50 ; TVA par ligne : 6 840 + 484,79 (484,785 arrondi au centime supérieur).
            'total_ht' => 38551.5,
            'total_ttc' => 45876.29,
            'lines_count' => 2,
            'is_adjusted' => false,
            'can_modify' => true,
            'can_cancel' => true,
            'submitted_by' => ['id' => $this->account->id, 'name' => 'Gérant C0012'],
            'comment' => 'Livraison avant 10 h',
            'total_vat' => 7324.79,
            'decided_at' => null,
            'decision_reason_code' => null,
            'decision_comment' => null,
            'modifiable_until' => '2026-10-08T12:00:00+00:00',
            'lines' => [
                [
                    'product_id' => $this->orange->id, 'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L',
                    'sales_unit_label' => 'Fardeau 6 × 1 L',
                    'photo' => ['thumb' => "/media/{$photo->id}/conversions/p0042-thumb.webp", 'medium' => "/media/{$photo->id}/conversions/p0042-medium.webp"],
                    'requested_qty' => 40, 'validated_qty' => null, 'shipped_qty' => null,
                    'unit_price_ht' => 900.0, 'vat_rate' => 19.0, 'amount_ht' => 36000.0, 'adjustment_reason_code' => null, 'adjustment_reason' => null, 'adjustment_comment' => null, 'shipping_gap_reason' => null,
                ],
                [
                    'product_id' => $this->pomme->id, 'sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'short_name' => $this->pomme->short_name,
                    'sales_unit_label' => $this->pomme->sales_unit_label, 'photo' => null,
                    'requested_qty' => 3, 'validated_qty' => null, 'shipped_qty' => null,
                    'unit_price_ht' => 850.5, 'vat_rate' => 19.0, 'amount_ht' => 2551.5, 'adjustment_reason_code' => null, 'adjustment_reason' => null, 'adjustment_comment' => null, 'shipping_gap_reason' => null,
                ],
            ],
            'history' => [
                ['from' => null, 'to' => 'SUBMITTED', 'version' => 1, 'at' => '2026-10-08T08:30:00+00:00', 'actor' => 'CLIENT', 'by_name' => 'Gérant C0012', 'comment' => null],
            ],
        ]);
        expect($order)->client_id->toBe($this->a->id)->client_request_id->toBe($id)->region_id->toBe($this->centre->id);
        Event::assertDispatched(OrderSubmitted::class, fn (OrderSubmitted $e) => $e->orderId === $order->id && $e->actorId === $this->account->id);
    });

    it('answers 200 with the existing order when the same request is sent again, whatever the content, account or time (RG-C07, I-54)', function () {
        $id = (string) Str::uuid();
        $first = ($this->store)(($this->body)(['client_request_id' => $id]))->assertCreated()->json();

        ($this->store)(($this->body)(['client_request_id' => $id, 'lines' => ($this->lines)([[$this->orange, 1]])]))
            ->assertOk()
            ->assertExactJson($first);

        // Plus tard, depuis un autre compte du client, après l'heure limite, client bloqué : même commande, état actuel.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 14:00', 'Africa/Algiers'));
        $this->a->forceFill(['status' => 'BLOCKED'])->save();
        $this->actingAs($this->second)->postJson('/api/v1/client/orders', ($this->body)(['client_request_id' => strtoupper($id), 'day' => '2026-10-11']))
            ->assertOk()
            ->assertJsonPath('id', $first['id'])
            ->assertJsonPath('version', 1)
            ->assertJsonPath('lines_count', 2)
            ->assertJsonPath('can_modify', false)
            ->assertJsonPath('can_cancel', false);

        expect(Order::query()->count())->toBe(1);
        Event::assertDispatchedTimes(OrderSubmitted::class, 1);
    });

    it('checks the format before recognising a replay (422)', function () {
        $id = (string) Str::uuid();
        ($this->store)(($this->body)(['client_request_id' => $id]))->assertCreated();

        expectProblem(($this->store)(($this->body)(['client_request_id' => $id, 'lines' => []])), 422, 'VALIDATION_FAILED');
    });

    it('answers 422 to a malformed request, without creating anything (I-60)', function (Closure $body, string $field) {
        $response = ($this->store)($body->call($this));

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        expect(Order::query()->count())->toBe(0);
    })->with([
        'identifiant absent' => [fn () => array_diff_key(($this->body)(), ['client_request_id' => true]), 'client_request_id'],
        'identifiant non UUID' => [fn () => ($this->body)(['client_request_id' => 'abc']), 'client_request_id'],
        'identifiant numérique' => [fn () => ($this->body)(['client_request_id' => 123]), 'client_request_id'],
        'journée absente' => [fn () => array_diff_key(($this->body)(), ['day' => true]), 'day'],
        'journée au format français' => [fn () => ($this->body)(['day' => '08/10/2026']), 'day'],
        'journée impossible' => [fn () => ($this->body)(['day' => '2026-02-30']), 'day'],
        'commentaire trop long' => [fn () => ($this->body)(['comment' => str_repeat('é', 501)]), 'comment'],
        'commentaire tableau' => [fn () => ($this->body)(['comment' => ['x']]), 'comment'],
        'lignes absentes' => [fn () => array_diff_key(($this->body)(), ['lines' => true]), 'lines'],
        'aucune ligne' => [fn () => ($this->body)(['lines' => []]), 'lines'],
        '201 lignes' => [fn () => ($this->body)(['lines' => ($this->lines)(array_map(fn (int $i) => [100000 + $i, 1], range(1, 201)))]), 'lines'],
        'lignes en objet' => [fn () => ($this->body)(['lines' => ['a' => ['product_id' => $this->orange->id, 'quantity' => 1]]]), 'lines'],
        'ligne non objet' => [fn () => ($this->body)(['lines' => [5]]), 'lines.0'],
        'produit absent' => [fn () => ($this->body)(['lines' => [['quantity' => 1]]]), 'lines.0.product_id'],
        'produit en texte' => [fn () => ($this->body)(['lines' => [['product_id' => (string) $this->orange->id, 'quantity' => 1]]]), 'lines.0.product_id'],
        'quantité absente' => [fn () => ($this->body)(['lines' => [['product_id' => $this->orange->id]]]), 'lines.0.quantity'],
        'quantité nulle' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, 0]])]), 'lines.0.quantity'],
        'quantité négative' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, -3]])]), 'lines.0.quantity'],
        'quantité au-delà de 1 000 000' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, 1000001]])]), 'lines.0.quantity'],
        'quantité décimale' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, 2.5]])]), 'lines.0.quantity'],
        'quantité en texte' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, '3']])]), 'lines.0.quantity'],
        'quantité booléenne' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, true]])]), 'lines.0.quantity'],
        'produit en double' => [fn () => ($this->body)(['lines' => ($this->lines)([[$this->orange, 1], [$this->pomme, 1], [$this->orange, 2]])]), 'lines.2.product_id'],
    ]);

    it('explains the format errors in French', function () {
        ($this->store)(($this->body)(['day' => '08/10/2026', 'lines' => ($this->lines)([[$this->orange, 0], [$this->orange, 1]])]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.day', ['Journée attendue au format AAAA-MM-JJ.'])
            ->assertJsonPath('errors', fn (array $errors) => $errors['lines.0.quantity'] === ['La quantité doit être un nombre entier de 1 à 1 000 000.']
                && $errors['lines.1.product_id'] === ['Ce produit figure déjà dans la commande.']);
    });

    it('accepts the limits of the format: 500 characters, 200 lines, 1 000 000 per line (I-60)', function () {
        ($this->store)(($this->body)(['comment' => str_repeat('é', 500)]))->assertCreated();

        // Au format permis, le contrôle passe à ClientOrders : produits inconnus, quantité au-dessus de la borne.
        app(Settings::class)->update(['orders' => ['max_per_day' => 2]], null);
        ($this->store)(($this->body)(['lines' => ($this->lines)(array_map(fn (int $i) => [100000 + $i, 1], range(1, 200)))]))
            ->assertStatus(422)->assertJsonPath('code', 'PRODUCT_NOT_AVAILABLE')->assertJsonCount(200, 'violations');
        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->orange, 1000000]])]))
            ->assertStatus(409)->assertJsonPath('code', 'CLIENT_LIMIT_EXCEEDED');
    });

    it('refuses a blocked client (CLIENT_BLOCKED, 403)', function () {
        $this->a->forceFill(['status' => 'BLOCKED'])->save();

        ($this->store)(($this->body)())->assertForbidden()->assertHeader('Content-Type', 'application/problem+json')->assertExactJson(
            ($this->problem)(ErrorCode::ClientBlocked, 'Votre compte client est bloqué : commande impossible. Contactez votre commercial régional.'),
        );
    });

    it('refuses a day not opened, not distributed or closed (DAY_NOT_OPEN, 409)', function () {
        expectProblem(($this->store)(($this->body)(['day' => '2026-10-11'])), 409, 'DAY_NOT_OPEN');
        ($this->store)(($this->body)(['day' => '2026-10-11']))->assertJsonPath('detail', 'La prise de commande n\'est pas encore ouverte pour cette journée.');

        RegionDay::query()->whereKey($this->regionDay->id)->update(['status' => 'CLOSED']);
        ($this->store)(($this->body)())->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_OPEN')
            ->assertJsonPath('detail', 'Cette journée est clôturée : commande impossible.');
    });

    it('refuses an order after the cutoff, server time (CUTOFF_PASSED, 409, RG-C08)', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'Africa/Algiers'));

        ($this->store)(($this->body)())->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::CutoffPassed, 'L\'heure limite de commande (13:00) est dépassée.'),
        );
    });

    it('refuses an order above the maximum of the day, with the maximum (MAX_ORDERS_REACHED, 409, RG-C04)', function () {
        ($this->order)();

        ($this->store)(($this->body)())->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::MaxOrdersReached, 'Vous avez déjà envoyé votre commande pour cette journée.', ['max_orders_per_day' => 1]),
        );
    });

    it('lists every product that is not available, line by line (PRODUCT_NOT_AVAILABLE, 422, I-46)', function () {
        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->orange, 1], [$this->fraise, 1], [999999, 1]])]))
            ->assertStatus(422)
            ->assertExactJson(($this->problem)(ErrorCode::ProductNotAvailable, '2 produits ne sont pas disponibles pour vous ce jour-là.', [
                'violations' => [['line' => 1, 'product_id' => $this->fraise->id], ['line' => 2, 'product_id' => 999999]],
            ]));
    });

    it('lists every quantity above the available one, the maximum only under a limit (CLIENT_LIMIT_EXCEEDED, 409, RG-C03, RG-C09)', function () {
        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->orange, 51]])]))
            ->assertStatus(409)
            ->assertExactJson(($this->problem)(ErrorCode::ClientLimitExceeded, '« Jus orange 1 L » : maximum 50.', [
                'violations' => [['line' => 0, 'product_id' => $this->orange->id, 'requested' => 51, 'max_allowed' => 50]],
            ]));

        // Sans borne : le reste de la région n'est jamais montré.
        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->pomme, 301]])]))
            ->assertStatus(409)
            ->assertExactJson(($this->problem)(ErrorCode::ClientLimitExceeded, '« Jus pomme 1 L » : quantité trop élevée, réduisez-la.', [
                'violations' => [['line' => 0, 'product_id' => $this->pomme->id, 'requested' => 301, 'max_allowed' => null]],
            ]));

        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->lben, 1], [$this->orange, 60], [$this->pomme, 400]])]))
            ->assertStatus(409)
            ->assertJsonPath('detail', '2 lignes dépassent la quantité disponible.')
            ->assertJsonPath('violations.*.line', [1, 2]);
    });

    it('refuses an order below the minimum amount, with the minimum and the total (ORDER_BELOW_MINIMUM, 422, RG-C06)', function () {
        app(Settings::class)->update(['orders' => ['min_amount' => 1000]], null);

        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->lben, 8]])]))
            ->assertStatus(422)
            ->assertExactJson(($this->problem)(ErrorCode::OrderBelowMinimum, 'Montant minimum de commande : 1 000,00 DA HT (commande : 960,00 DA HT).', [
                'min_amount_ht' => 1000, 'total_ht' => 960,
            ]));
        ($this->store)(($this->body)(['lines' => ($this->lines)([[$this->lben, 9]])]))->assertCreated();
    });

    it('applies the checks in the order of the contract', function () {
        $unavailable = ($this->lines)([[$this->fraise, 1], [$this->orange, 99]]);
        $exceeded = ($this->lines)([[$this->orange, 99]]);
        $code = fn (array $overrides) => ($this->store)(($this->body)($overrides))->json('code');
        app(Settings::class)->update(['orders' => ['min_amount' => 1000000]], null);

        // Produit indisponible avant quantité dépassée ; quantité dépassée avant montant minimum.
        expect($code(['lines' => $unavailable]))->toBe('PRODUCT_NOT_AVAILABLE')
            ->and($code(['lines' => $exceeded]))->toBe('CLIENT_LIMIT_EXCEEDED')
            ->and($code(['lines' => ($this->lines)([[$this->orange, 1]])]))->toBe('ORDER_BELOW_MINIMUM');

        // Nombre de commandes avant les lignes.
        app(Settings::class)->update(['orders' => ['min_amount' => null]], null);
        ($this->order)();
        expect($code(['lines' => $unavailable]))->toBe('MAX_ORDERS_REACHED');

        // Journée avant le nombre de commandes ; heure limite avant le nombre de commandes.
        expect($code(['day' => '2026-10-11', 'lines' => $unavailable]))->toBe('DAY_NOT_OPEN');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:30', 'Africa/Algiers'));
        expect($code(['lines' => $unavailable]))->toBe('CUTOFF_PASSED');

        // Client bloqué avant tout le reste.
        $this->a->forceFill(['status' => 'BLOCKED'])->save();
        expect($code(['day' => '2026-10-11', 'lines' => $unavailable]))->toBe('CLIENT_BLOCKED');
    });
});

describe('PATCH /client/orders/{id}', function () {
    it('replaces the lines and the comment, with a new version, its own quantities excluded from E (RG-C05, I-47, I-51)', function () {
        $order = ($this->order)(['comment' => 'Avant 10 h']);
        // Prix du jour changé : l'orange gardée garde 900, le lben ajouté prend 130.
        ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '990.00', 'valid_from' => '2026-10-08']);
        ProductPrice::factory()->create(['product_id' => $this->lben->id, 'price_ht' => '130.00', 'valid_from' => '2026-10-08']);

        $response = $this->actingAs($this->second)->patchJson("/api/v1/client/orders/{$order->public_id}", [
            'version' => 1, 'comment' => null, 'lines' => ($this->lines)([[$this->orange, 50], [$this->lben, 10]]),
        ]);

        // 50 × 900 + 10 × 130 = 46 300 HT ; TVA 8 550 + 117 = 8 667.
        $response->assertOk()
            ->assertJsonPath('id', $order->public_id)
            ->assertJsonPath('number', $order->number)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('comment', null)
            ->assertJsonPath('total_ht', 46300)
            ->assertJsonPath('total_vat', 8667)
            ->assertJsonPath('total_ttc', 54967)
            ->assertJsonPath('lines_count', 2)
            ->assertJsonPath('submitted_at', '2026-10-08T08:30:00+00:00')
            ->assertJsonPath('submitted_by.name', 'Gérant C0012')
            ->assertJsonPath('can_modify', true)
            ->assertJsonPath('modifiable_until', '2026-10-08T12:00:00+00:00')
            ->assertJsonPath('lines.*.product_id', [$this->orange->id, $this->lben->id])
            ->assertJsonPath('lines.*.requested_qty', [50, 10])
            ->assertJsonPath('lines.*.unit_price_ht', [900, 130])
            ->assertJsonPath('history.*.version', [1, 2])
            ->assertJsonPath('history.1', [
                'from' => 'SUBMITTED', 'to' => 'SUBMITTED', 'version' => 2, 'at' => '2026-10-08T08:30:00+00:00',
                'actor' => 'CLIENT', 'by_name' => 'Vendeur C0012', 'comment' => null,
            ]);
        Event::assertDispatched(OrderUpdated::class, fn (OrderUpdated $e) => $e->orderId === $order->id && $e->version === 2);

        // Rien de changé : même version, pas d'événement.
        ($this->patch)($order, ['version' => 2, 'comment' => '', 'lines' => ($this->lines)([[$this->lben, 10], [$this->orange, 50]])])
            ->assertOk()
            ->assertJsonPath('version', 2);
        Event::assertDispatchedTimes(OrderUpdated::class, 1);
    });

    it('answers 422 to a malformed modification', function (Closure $body, string $field) {
        $order = ($this->order)();

        $response = ($this->patch)($order, $body(['version' => 1, 'comment' => null, 'lines' => ($this->lines)([[$this->orange, 10]])]));

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
        expect($order->fresh()->version)->toBe(1);
    })->with([
        'version absente' => [fn (array $body) => array_diff_key($body, ['version' => true]), 'version'],
        'version en texte' => [fn (array $body) => [...$body, 'version' => '1'], 'version'],
        'version décimale' => [fn (array $body) => [...$body, 'version' => 1.5], 'version'],
        'version nulle' => [fn (array $body) => [...$body, 'version' => 0], 'version'],
        'commentaire absent' => [fn (array $body) => array_diff_key($body, ['comment' => true]), 'comment'],
        'commentaire trop long' => [fn (array $body) => [...$body, 'comment' => str_repeat('x', 501)], 'comment'],
        'aucune ligne (annuler plutôt)' => [fn (array $body) => [...$body, 'lines' => []], 'lines'],
        'quantité nulle' => [fn (array $body) => [...$body, 'lines' => [['product_id' => 1, 'quantity' => 0]]], 'lines.0.quantity'],
        'produit en double' => [fn (array $body) => [...$body, 'lines' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 1, 'quantity' => 2]]], 'lines.1.product_id'],
    ]);

    it('refuses a modification that is no longer possible, in the order of the contract', function () {
        $order = ($this->order)();
        $patch = fn (int $version, ?array $lines = null) => ($this->patch)($order, [
            'version' => $version, 'comment' => null, 'lines' => ($this->lines)($lines ?? [[$this->orange, 1]]),
        ]);
        ($this->patch)($order, ['version' => 1, 'comment' => null, 'lines' => ($this->lines)([[$this->orange, 20]])])->assertOk();

        // Version périmée : la version actuelle est rendue.
        $patch(1)->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::VersionConflict, 'La commande a été modifiée entre-temps : rechargez-la.', ['version' => 2]),
        );

        // Produits, puis disponible, puis montant minimum.
        expect($patch(2, [[$this->fraise, 1], [$this->orange, 99]])->assertStatus(422)->json())
            ->toMatchArray(['code' => 'PRODUCT_NOT_AVAILABLE', 'violations' => [['line' => 0, 'product_id' => $this->fraise->id]]]);
        // E exclut la commande modifiée : 50 permis, 51 refusé.
        expect($patch(2, [[$this->orange, 51]])->assertStatus(409)->json())
            ->toMatchArray(['code' => 'CLIENT_LIMIT_EXCEEDED', 'violations' => [['line' => 0, 'product_id' => $this->orange->id, 'requested' => 51, 'max_allowed' => 50]]]);
        app(Settings::class)->update(['orders' => ['min_amount' => 50000]], null);
        expect($patch(2, [[$this->orange, 50]])->assertStatus(422)->json())
            ->toMatchArray(['code' => 'ORDER_BELOW_MINIMUM', 'min_amount_ht' => 50000, 'total_ht' => 45000]);
        app(Settings::class)->update(['orders' => ['min_amount' => null]], null);
        $patch(2, [[$this->orange, 50]])->assertOk()->assertJsonPath('version', 3);

        // Heure limite passée, puis journée de la région clôturée (avant les lignes).
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'Africa/Algiers'));
        expect($patch(3, [[$this->fraise, 1]])->assertStatus(409)->json('code'))->toBe('CUTOFF_PASSED');
        RegionDay::query()->whereKey($this->regionDay->id)->update(['status' => 'CLOSED']);
        expect($patch(3, [[$this->fraise, 1]])->assertStatus(409)->json('code'))->toBe('DAY_NOT_OPEN');

        // Client bloqué : avant la journée, après la version.
        $this->a->forceFill(['status' => 'BLOCKED'])->save();
        expectProblem($patch(3), 403, 'CLIENT_BLOCKED');
        expect($patch(1)->json('code'))->toBe('VERSION_CONFLICT');

        // Commande annulée : transition interdite, avant la version.
        $order->forceFill(['status' => 'CANCELLED'])->save();
        $patch(1)->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::InvalidTransition, 'Commande « Annulée » : impossible de passer à « Envoyée ».'),
        );
        expect($order->fresh()->lines()->pluck('requested_qty')->all())->toBe([50]);
    });
});

describe('POST /client/orders/{id}/cancel', function () {
    it('cancels the order: new version, no action left (§6.3, I-52)', function () {
        $order = ($this->order)();

        $response = $this->actingAs($this->second)->postJson("/api/v1/client/orders/{$order->public_id}/cancel", ['version' => 1]);

        $response->assertOk()
            ->assertJsonPath('id', $order->public_id)
            ->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('status_label', 'Annulée')
            ->assertJsonPath('version', 2)
            ->assertJsonPath('can_modify', false)
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('modifiable_until', null)
            ->assertJsonPath('lines_count', 2)
            ->assertJsonPath('history.*.to', ['SUBMITTED', 'CANCELLED'])
            ->assertJsonPath('history.1.by_name', 'Vendeur C0012');
        Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $e) => $e->orderId === $order->id && $e->version === 2);

        // Le maximum du jour est de nouveau libre (RG-C04).
        ($this->store)(($this->body)())->assertCreated();
    });

    it('lets a blocked client cancel (I-53)', function () {
        $order = ($this->order)();
        $this->a->forceFill(['status' => 'BLOCKED'])->save();

        ($this->cancel)($order, ['version' => 1])->assertOk()->assertJsonPath('status', 'CANCELLED');
    });

    it('answers 422 to a malformed cancellation', function (array $body) {
        $order = ($this->order)();

        $response = ($this->cancel)($order, $body);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['version']]);
        expect($order->fresh()->status->value)->toBe('SUBMITTED');
    })->with([
        'version absente' => [[]],
        'version en texte' => [['version' => '1']],
        'version décimale' => [['version' => 1.5]],
        'version booléenne' => [['version' => true]],
    ]);

    it('refuses a cancellation that is no longer possible, in the order of the contract', function () {
        $order = ($this->order)();
        ($this->patch)($order, ['version' => 1, 'comment' => null, 'lines' => ($this->lines)([[$this->orange, 20]])])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:01', 'Africa/Algiers'));
        ($this->cancel)($order, ['version' => 2])->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::CutoffPassed, 'L\'heure limite de commande (13:00) est dépassée.'),
        );
        RegionDay::query()->whereKey($this->regionDay->id)->update(['status' => 'CLOSED']);
        expect(($this->cancel)($order, ['version' => 2])->assertStatus(409)->json('code'))->toBe('DAY_NOT_OPEN');

        // Version avant la journée ; transition avant la version.
        ($this->cancel)($order, ['version' => 1])->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::VersionConflict, 'La commande a été modifiée entre-temps : rechargez-la.', ['version' => 2]),
        );
        $order->forceFill(['status' => 'EXPIRED'])->save();
        ($this->cancel)($order, ['version' => 1])->assertStatus(409)->assertExactJson(
            ($this->problem)(ErrorCode::InvalidTransition, 'Commande « Expirée » : impossible de passer à « Annulée ».'),
        );
        expect($order->fresh()->version)->toBe(2);
    });
});
