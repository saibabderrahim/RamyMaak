<?php

namespace App\Models;

use Database\Factories\ClientLimitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borne d'un client pour un produit, une région et une journée (RG-B01) :
 * plafond publié B(c,p) (null = pas de borne) et brouillon en attente
 * d'envoi (`has_draft`, `draft_max_qty` null = supprimer la borne).
 *
 * @property int $id
 * @property int $region_day_id
 * @property int $client_id
 * @property int $product_id
 * @property ?int $max_qty
 * @property ?int $draft_max_qty
 * @property bool $has_draft
 * @property ?int $updated_by
 */
class ClientLimit extends Model
{
    /** @use HasFactory<ClientLimitFactory> */
    use HasFactory;

    protected $fillable = ['region_day_id', 'client_id', 'product_id', 'max_qty', 'draft_max_qty', 'has_draft', 'updated_by'];

    protected $attributes = [
        'has_draft' => false,
    ];

    protected function casts(): array
    {
        return [
            'region_day_id' => 'integer',
            'client_id' => 'integer',
            'product_id' => 'integer',
            'max_qty' => 'integer',
            'draft_max_qty' => 'integer',
            'has_draft' => 'boolean',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<RegionDay, $this> */
    public function regionDay(): BelongsTo
    {
        return $this->belongsTo(RegionDay::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Valeur affichée sur la grille : brouillon en attente, sinon borne publiée. */
    public function displayed(): ?int
    {
        return $this->has_draft ? $this->draft_max_qty : $this->max_qty;
    }
}
