<?php

use App\Models\PushSubscription;
use App\Models\PushThrottle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

// Purge nocturne des push (§9.7, contrat du lot 8 §2.4).

it('purges the failing and unused devices and the old anti-saturation states', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 03:10', 'Africa/Algiers'));
    $user = User::factory()->create();
    $device = fn (array $attributes, ?DateTimeInterface $createdAt = null) => tap(
        PushSubscription::factory()->create(['user_id' => $user->id, ...$attributes]),
        fn (PushSubscription $subscription) => $createdAt === null ? null : $subscription->forceFill(['created_at' => $createdAt])->saveQuietly(),
    );
    $failing = $device(['failures' => 3, 'last_used_at' => now()]);
    $flaky = $device(['failures' => 2, 'last_used_at' => now()]);
    $unused = $device(['last_used_at' => now()->subDays(61)], now()->subDays(100));
    $used = $device(['last_used_at' => now()->subDays(59)], now()->subDays(100));
    $neverUsed = $device(['last_used_at' => null], now()->subDays(61));
    $recent = $device(['last_used_at' => null], now()->subDays(10));
    $throttle = fn (string $group, int $days) => tap(
        PushThrottle::query()->create(['user_id' => $user->id, 'group' => $group, 'pending' => 0]),
        fn (PushThrottle $state) => $state->forceFill(['updated_at' => now()->subDays($days)])->saveQuietly(),
    );
    $old = $throttle('ORDER_SUBMITTED', 8);
    $kept = $throttle('ORDER_DECIDED', 6);

    $this->artisan('ramymaak:purge-push-subscriptions')
        ->expectsOutput('1 abonnement(s) en échec, 2 abonnement(s) inutilisé(s), 1 état(s) d\'anti-saturation supprimé(s).')
        ->assertSuccessful();

    expect(PushSubscription::query()->orderBy('id')->pluck('id')->all())->toBe([$flaky->id, $used->id, $recent->id])
        ->and(PushThrottle::query()->pluck('id')->all())->toBe([$kept->id]);
    expect(PushSubscription::query()->whereKey([$failing->id, $unused->id, $neverUsed->id])->exists())->toBeFalse()
        ->and(PushThrottle::query()->whereKey($old->id)->exists())->toBeFalse();

    // Rejouable : rien de plus à supprimer.
    $this->artisan('ramymaak:purge-push-subscriptions')
        ->expectsOutput('0 abonnement(s) en échec, 0 abonnement(s) inutilisé(s), 0 état(s) d\'anti-saturation supprimé(s).')
        ->assertSuccessful();
});

it('is scheduled at 03:10, Algiers time, without overlapping', function () {
    app(Kernel::class)->bootstrap();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'ramymaak:purge-push-subscriptions'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('10 3 * * *')
        ->and($events->first()->timezone)->toBe('Africa/Algiers')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(30);
});
