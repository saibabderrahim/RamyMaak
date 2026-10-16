<?php

namespace App\Models;

use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de commande (§10.2) : un produit au plus une fois par commande
 * (RG-C02), prix HT et taux de TVA figés à l'envoi (RG-P03). Le montant
 * de la ligne n'est pas stocké : App\Support\Money le calcule.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $requested_qty
 * @property ?int $validated_qty
 * @property ?int $shipped_qty
 * @property string $unit_price_ht
 * @property string $vat_rate
 * @property ?string $adjustment_reason_code
 * @property ?string $shipping_gap_reason_code
 */
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'requested_qty', 'validated_qty', 'shipped_qty', 'unit_price_ht', 'vat_rate'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'product_id' => 'integer',
            'requested_qty' => 'integer',
            'validated_qty' => 'integer',
            'shipped_qty' => 'integer',
            'unit_price_ht' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
