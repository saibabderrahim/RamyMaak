<?php

namespace Database\Factories;

use App\Domain\Orders\OrderStatus;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
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
            'public_id' => strtoupper((string) Str::ulid()),
            'number' => 'RMK-'.fake()->unique()->numerify('######-#####'),
            'business_day_id' => BusinessDay::factory(),
            'client_id' => Client::factory(),
            // Région du client à l'envoi (§10.2).
            'region_id' => fn (array $attributes) => Client::query()->whereKey($attributes['client_id'])->value('region_id'),
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

    /**
     * Validée (lot 6), sans auteur ni compteur : pour un V(r,p) réel, passer
     * par validateOrder() (OrderDecisions).
     */
    public function validated(): static
    {
        return $this->status(OrderStatus::Validated)->state(fn () => ['decided_at' => now()]);
    }

    /** En préparation (RG-A02). */
    public function inPreparation(): static
    {
        return $this->validated()->status(OrderStatus::InPreparation)->state(fn () => ['prep_started_at' => now()]);
    }

    /** Préparée (RG-A02) : quantités expédiées à poser sur les lignes (OrderLineFactory::shippedQty). */
    public function prepared(): static
    {
        return $this->inPreparation()->status(OrderStatus::Prepared)->state(fn () => ['prepared_at' => now()]);
    }

    /** Expédiée (RG-A02), bon de livraison facultatif. */
    public function shipped(?string $deliveryNoteRef = null): static
    {
        return $this->prepared()->status(OrderStatus::Shipped)->state(fn () => ['shipped_at' => now(), 'delivery_note_ref' => $deliveryNoteRef]);
    }

    /**
     * Lignes créées avec la commande : [[Product, demandé], …] ou
     * [[Product, demandé, validé], …], prix 900,00 DA HT et TVA du produit.
     * Quantité expédiée = validée pour une commande préparée ou expédiée.
     *
     * @param  list<array{0: Product, 1: int, 2?: ?int}>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(function (Order $order) use ($lines): void {
            $shipped = in_array($order->status, [OrderStatus::Prepared, OrderStatus::Shipped, OrderStatus::Delivered], true);
            foreach ($lines as $line) {
                $validated = $line[2] ?? null;
                $order->lines()->create([
                    'product_id' => $line[0]->id,
                    'requested_qty' => $line[1],
                    'validated_qty' => $validated,
                    'shipped_qty' => $shipped ? $validated : null,
                    'unit_price_ht' => '900.00',
                    'vat_rate' => $line[0]->vat_rate ?? '19.00',
                ]);
            }
        });
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
