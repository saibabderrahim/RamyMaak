<?php

namespace Database\Factories;

use App\Domain\Orders\OrderStatus;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Commande envoyée, sans ligne (OrderLine::factory()->for($order) pour en
 * ajouter). Pour une commande conforme aux règles, passer par ClientOrders.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'RMK-'.fake()->unique()->numerify('######-#####'),
            'business_day_id' => BusinessDay::factory(),
            'region_id' => Region::factory(),
            'client_id' => Client::factory(),
            'status' => OrderStatus::Submitted,
            'version' => 1,
            'client_request_id' => (string) Str::uuid(),
            'submitted_at' => now(),
            'total_ht' => 0,
            'total_vat' => 0,
            'total_ttc' => 0,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function validated(): static
    {
        return $this->status(OrderStatus::Validated)->state(fn () => ['decided_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->status(OrderStatus::Rejected)->state(fn () => ['decided_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->status(OrderStatus::Cancelled);
    }

    public function expired(): static
    {
        return $this->status(OrderStatus::Expired);
    }
}
