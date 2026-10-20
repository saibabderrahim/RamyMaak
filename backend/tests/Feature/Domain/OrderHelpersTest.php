<?php

use App\Domain\Access\Role;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderHistory;
use App\Domain\Orders\OrderLocks;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderVersion;
use App\Http\Problems\ApiException;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Region;
use App\Models\RegionDay;

// Outils communs des écrivains de commandes (extraits de ClientOrders,
// lot 6) : version conditionnelle, historique avec l'acteur, verrous.

it('bumps the version only from the expected status and version, else VERSION_CONFLICT with the current one (§5.8 étape 1)', function () {
    $order = Order::factory()->create(['version' => 3]);
    $versions = app(OrderVersion::class);

    expect(fn () => $versions->assert($order, 2))->toThrow(fn (ApiException $e) => expect($e->extra)->toBe(['version' => 3]));
    $versions->assert($order, 3);

    expect(fn () => $versions->bump($order, 2, OrderStatus::Submitted, OrderStatus::Validated))
        ->toThrow(fn (ApiException $e) => expect([$e->errorCode->value, $e->extra])->toBe(['VERSION_CONFLICT', ['version' => 3]]));
    expect(fn () => $versions->bump($order, 3, OrderStatus::Validated, OrderStatus::InPreparation))->toThrow(ApiException::class);

    $versions->bump($order, 3, OrderStatus::Submitted, OrderStatus::Validated, ['is_adjusted' => true]);
    expect($order->only(['status', 'version', 'is_adjusted']))->toBe(['status' => OrderStatus::Validated, 'version' => 4, 'is_adjusted' => true])
        ->and($order->fresh()->only(['status', 'version', 'is_adjusted']))->toBe(['status' => OrderStatus::Validated, 'version' => 4, 'is_adjusted' => true]);
});

it('records each transition with its actor and the version reached, one or many at once (§6.3, I-65)', function () {
    $history = app(OrderHistory::class);
    $day = BusinessDay::factory()->create();
    $first = Order::factory()->create(['version' => 2, 'business_day_id' => $day->id]);
    $second = Order::factory()->create(['version' => 5, 'business_day_id' => $day->id]);
    $regional = userWithRole(Role::Regional);

    $history->record($first, OrderStatus::Submitted, OrderStatus::Rejected, OrderActor::Regional, $regional->id, 'Doublon');
    $history->recordMany([$first, $second], OrderStatus::Validated, OrderStatus::InPreparation, OrderActor::Adv, null);
    $history->recordMany([], OrderStatus::Validated, OrderStatus::InPreparation, OrderActor::Adv, null);

    expect(OrderStatusHistory::query()->orderBy('id')->get()->map->only(['order_id', 'from_status', 'to_status', 'version', 'user_id', 'actor', 'comment'])->all())->toBe([
        ['order_id' => $first->id, 'from_status' => OrderStatus::Submitted, 'to_status' => OrderStatus::Rejected, 'version' => 2, 'user_id' => $regional->id, 'actor' => OrderActor::Regional, 'comment' => 'Doublon'],
        ['order_id' => $first->id, 'from_status' => OrderStatus::Validated, 'to_status' => OrderStatus::InPreparation, 'version' => 2, 'user_id' => null, 'actor' => OrderActor::Adv, 'comment' => null],
        ['order_id' => $second->id, 'from_status' => OrderStatus::Validated, 'to_status' => OrderStatus::InPreparation, 'version' => 5, 'user_id' => null, 'actor' => OrderActor::Adv, 'comment' => null],
    ]);
});

it('reads the locked rows by ascending identifier (I-67)', function () {
    $locks = app(OrderLocks::class);
    $days = BusinessDay::factory()->count(3)->sequence(fn ($s) => ['day' => '2026-10-0'.($s->index + 1)])->create();
    $orders = Order::factory()->count(3)->create(['business_day_id' => $days[0]->id]);
    $region = Region::factory()->create();
    $regionDay = RegionDay::factory()->create(['business_day_id' => $days[0]->id, 'region_id' => $region->id]);

    expect($locks->days([$days[2]->id, $days[0]->id, $days[2]->id])->keys()->all())->toBe([$days[0]->id, $days[2]->id])
        ->and($locks->days([]))->toBeEmpty()
        ->and($locks->orders([$orders[2]->id, $orders[1]->id])->keys()->all())->toBe([$orders[1]->id, $orders[2]->id])
        ->and($locks->order(999_999))->toBeNull()
        ->and($locks->regionDay($days[0]->id, $region->id)?->id)->toBe($regionDay->id)
        ->and($locks->regionDay($days[1]->id, $region->id))->toBeNull()
        ->and($locks->day($days[1]->id)->id)->toBe($days[1]->id)
        ->and($locks->client($orders[0]->client_id)?->id)->toBe($orders[0]->client_id);
});
