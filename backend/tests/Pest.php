<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Models\BusinessDay;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes);
}

function admin(array $attributes = []): User
{
    return userWithRole(Role::Admin, $attributes);
}

/**
 * Vérifie le format « Problem Details » (RFC 9457) et le code métier.
 */
function expectProblem(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'detail'])
        ->assertJsonPath('status', $status)
        ->assertJsonPath('code', $code);
}

/**
 * Dans un test, l'application (et donc ses gardes d'authentification) est
 * partagée entre requêtes ; en production chaque requête repart de zéro.
 * À appeler avant une requête censée relire la session.
 */
function forgetGuards(): void
{
    app('auth')->forgetGuards();
}

/**
 * Prise de commande ouverte (lot 5) : journée ouverte, quotas publiés par
 * l'ADV et répartition envoyée par le régional pour la région.
 * `$quotas` : [[Product, quantité], …] ; `$limits` : [[Client, Product, borne], …].
 * Les produits sans prix reçoivent 900,00 DA HT (TVA du produit).
 */
function openOrderingDay(string $date, Region $region, array $quotas, array $limits = []): RegionDay
{
    $adv = userWithRole(Role::Adv);
    $regional = RegionAssignment::query()->where('region_id', $region->id)->first()?->user
        ?? tap(userWithRole(Role::Regional), fn (User $user) => RegionAssignment::factory()->create(['region_id' => $region->id, 'user_id' => $user->id]));

    $day = BusinessDay::query()->where('day', $date)->first()
        ?? app(DayCalendar::class)->open(BusinessClock::parseDay($date), $adv);
    $cells = [];
    foreach ($quotas as [$product, $quantity]) {
        if (! $product->prices()->where('valid_from', '<=', $date)->exists()) {
            ProductPrice::factory()->create(['product_id' => $product->id, 'price_ht' => '900.00', 'valid_from' => '2026-01-01']);
        }
        $cells[] = ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
    }
    $quotaBook = app(QuotaBook::class);
    $quotaBook->saveDrafts($day, $cells, $adv);
    $quotaBook->publish($day, [$region->id], $adv);

    $regionDay = RegionDay::query()->where('business_day_id', $day->id)->where('region_id', $region->id)->sole();
    $limitBook = app(LimitBook::class);
    $limitBook->saveDrafts($regionDay, array_map(fn (array $limit) => [
        'client_id' => $limit[0]->id, 'product_id' => $limit[1]->id, 'limit' => $limit[2],
    ], $limits), $regional);
    $limitBook->publish($regionDay, $regional);

    return $regionDay->fresh(['businessDay', 'region']);
}
