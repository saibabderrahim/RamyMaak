<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\DecisionRights;
use App\Domain\Orders\ValidationWindow;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\OrderSubmitted;
use App\Events\OrderValidated;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// Droits de décision, fenêtre de validation et contexte des lignes pour les
// vues du régional (EF-REG-05, RG-V08 ; contrat du lot 6, §3) : mêmes règles
// et même formule que OrderDecisions.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([OrderSubmitted::class, OrderValidated::class, CutoffExtended::class]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);
    $this->a = Client::factory()->create(['region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create();
    $this->pomme = Product::factory()->create();
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 100], [$this->pomme, 300]], [[$this->a, $this->orange, 50]]);
    $this->rights = app(DecisionRights::class);
    $this->submit = fn (Client $client, array $lines): Order => app(ClientOrders::class)->submit(
        userWithRole(Role::Client, ['client_id' => $client->id]),
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];
    $this->state = fn (Order $order, ?User $user = null): array => $this->rights->evaluate($user ?? $this->regional, $order->fresh())->toArray();
});

it('computes the quantity validatable of a line (RG-V04, I-91)', function () {
    expect(DecisionRights::maxValidatable(40, 300, 290, 50, 10))->toBe(10)
        ->and(DecisionRights::maxValidatable(40, 300, 0, 50, 30))->toBe(20)
        ->and(DecisionRights::maxValidatable(40, 300, 0, null, 999))->toBe(40)
        ->and(DecisionRights::maxValidatable(40, 300, 0, 0, 0))->toBe(0)
        ->and(DecisionRights::maxValidatable(40, 100, 120, null, 0))->toBe(0)
        ->and(DecisionRights::maxValidatable(40, 0, 0, null, 0))->toBe(0);
});

it('tells whether the regional can validate or reject the order, and why not (contrat §3)', function () {
    $order = ($this->submit)($this->a, [[$this->orange, 40]]);
    $other = ($this->submit)($this->b, [[$this->pomme, 5]]);

    expect(($this->state)($order))->toBe([
        'validation' => ['cutoff' => '15:00', 'cutoff_at' => '2026-10-08T14:00:00+00:00', 'extended' => false, 'blocking' => false, 'passed' => false],
        'can_validate' => true,
        'can_reject' => true,
        'blocked_reason' => null,
    ])
        // Ni l'ADV ni l'Admin ne décident.
        ->and(($this->state)($order, userWithRole(Role::Adv)))->toMatchArray(['can_validate' => false, 'can_reject' => false, 'blocked_reason' => null])
        ->and(($this->state)($order, admin()))->toMatchArray(['can_validate' => false, 'can_reject' => false]);

    $this->a->forceFill(['status' => 'BLOCKED'])->save();
    expect(($this->state)($order))->toMatchArray(['can_validate' => false, 'can_reject' => true, 'blocked_reason' => 'CLIENT_BLOCKED']);

    // Heure limite bloquante atteinte : CUTOFF_PASSED d'abord ; une prolongation de la région la repousse.
    app(Settings::class)->update(['cutoffs' => ['validation_blocking' => true]], null);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Africa/Algiers'));
    expect(($this->state)($order))->toMatchArray(['can_validate' => false, 'can_reject' => true, 'blocked_reason' => 'CUTOFF_PASSED'])
        ->and(($this->state)($order)['validation'])->toMatchArray(['blocking' => true, 'passed' => true]);
    app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Validation, '16:00', userWithRole(Role::Adv));
    expect(($this->state)($order)['validation'])->toBe(['cutoff' => '16:00', 'cutoff_at' => '2026-10-08T15:00:00+00:00', 'extended' => true, 'blocking' => true, 'passed' => false])
        ->and(($this->state)($order)['blocked_reason'])->toBe('CLIENT_BLOCKED');

    BusinessDay::query()->whereKey($this->regionDay->business_day_id)->update(['status' => 'CLOSED']);
    expect(($this->state)($order))->toMatchArray(['can_validate' => false, 'can_reject' => false, 'blocked_reason' => 'DAY_NOT_OPEN']);

    // Commande déjà décidée : plus aucune action.
    BusinessDay::query()->whereKey($this->regionDay->business_day_id)->update(['status' => 'OPEN']);
    app(Settings::class)->update(['cutoffs' => ['validation_blocking' => false]], null);
    validateOrder($other, by: $this->regional);
    expect(($this->state)($other))->toMatchArray(['can_validate' => false, 'can_reject' => false, 'blocked_reason' => null]);
});

it('gives each line its published context and the maximum validatable, the same as the validation (EF-REG-05, RG-V04)', function () {
    $order = ($this->submit)($this->a, [[$this->orange, 40], [$this->pomme, 5]]);
    validateOrder(($this->submit)($this->b, [[$this->orange, 70]]));

    expect($this->rights->context($order->fresh()))->toBe([
        $this->orange->id => ['limit' => 50, 'client_validated' => 0, 'region_quota' => 100, 'region_validated' => 70, 'region_remaining' => 30, 'max_validatable' => 30],
        $this->pomme->id => ['limit' => null, 'client_validated' => 0, 'region_quota' => 300, 'region_validated' => 0, 'region_remaining' => 300, 'max_validatable' => 5],
    ]);

    // « Ajuster au maximum possible » : accepté tel quel.
    $validated = validateOrder($order, [$this->orange->id => 30, $this->pomme->id => 5]);
    // Une fois validée, la commande ne compte pas ses propres quantités dans le maximum.
    expect($this->rights->context($validated)[$this->orange->id])->toBe([
        'limit' => 50, 'client_validated' => 30, 'region_quota' => 100, 'region_validated' => 100, 'region_remaining' => 0, 'max_validatable' => 30,
    ]);
});

it('uses the day cutoff when the region day does not exist', function () {
    $day = BusinessDay::factory()->on('2026-10-09')->create();

    expect(app(ValidationWindow::class)->state($day, null)->toArray())->toBe([
        'cutoff' => '15:00', 'cutoff_at' => '2026-10-09T14:00:00+00:00', 'extended' => false, 'blocking' => false, 'passed' => false,
    ]);
});
