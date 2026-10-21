<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use Illuminate\Validation\Validator;

/**
 * Envoi et modification d'une commande (RG-C02, I-60) : `order.create`.
 * Lignes : 1 à 200 éléments `{product_id, quantity}`, entiers JSON stricts,
 * quantité de 1 à 1 000 000, un produit au plus une fois (422 sur la
 * seconde occurrence). Commentaire : null ou 500 caractères au plus.
 *
 * Disponibilité des produits, quantités et montant minimum sont contrôlés
 * par ClientOrders, sous le verrou du client (§5.8).
 */
abstract class ClientOrderWriteRequest extends ClientOrderRequest
{
    public const MAX_LINES = 200;

    public const MAX_QUANTITY = 1_000_000;

    public const MAX_COMMENT = 500;

    protected function permissions(): array
    {
        return [Permission::OrderCreate];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            // Entiers JSON stricts : ni « 12 », ni 12.5, ni true.
            'lines.*.product_id' => ['required', 'integer:strict'],
            'lines.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /** @return list<string> */
    protected function commentRules(): array
    {
        return ['nullable', 'string', 'max:'.self::MAX_COMMENT];
    }

    /**
     * Un produit au plus une fois par commande (RG-C02) : l'erreur porte sur
     * la seconde occurrence.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $lines = $this->input('lines');
            if (! is_array($lines)) {
                return;
            }

            $seen = [];
            foreach ($lines as $index => $line) {
                if (! is_array($line) || ! is_int($line['product_id'] ?? null)) {
                    continue;
                }
                if (isset($seen[$line['product_id']])) {
                    $validator->errors()->add("lines.{$index}.product_id", __('orders.client.duplicate_line'));

                    continue;
                }
                $seen[$line['product_id']] = true;
            }
        }];
    }

    /**
     * Lignes validées, dans l'ordre reçu (les violations de ClientOrders portent les mêmes indices).
     *
     * @return list<array{product_id: int, quantity: int}>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
        ], $this->validated('lines')));
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) ? $comment : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $quantity = __('orders.client.quantity_format', ['max' => number_format(self::MAX_QUANTITY, 0, ',', ' ')]);

        return [
            'list' => __('validation.array'),
            'lines.*.quantity.integer' => $quantity,
            'lines.*.quantity.min' => $quantity,
            'lines.*.quantity.max' => $quantity,
        ];
    }
}
