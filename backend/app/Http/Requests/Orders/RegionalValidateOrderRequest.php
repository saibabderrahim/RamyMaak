<?php

namespace App\Http\Requests\Orders;

use App\Domain\Orders\OrderDecisions;
use App\Domain\Reasons\ReasonType;
use App\Rules\ActiveReasonCode;
use Illuminate\Validation\Validator;

/**
 * Validation d'une commande envoyée (EF-REG-05 ; contrat du lot 6, §3) :
 * `version` et toutes les lignes (I-72), en forme seulement : 1 à 200
 * éléments `{product_id, validated_qty, reason_code?, comment?}`, produits
 * distincts (422 sur la seconde occurrence), quantité entière JSON stricte
 * de 0 à 1 000 000, motif d'un code ADJUSTMENT actif, commentaire de 200
 * caractères au plus (I-73).
 *
 * Le sens (produits de la commande, quantité ≤ demandé, motif sur les
 * lignes réduites seulement, commentaire exigé par le motif, pas toutes les
 * lignes à 0) est contrôlé par OrderDecisions après les verrous : une
 * modification du client entre-temps est un conflit de version, non une
 * erreur de saisie.
 */
class RegionalValidateOrderRequest extends RegionalDecisionRequest
{
    public const MAX_LINES = 200;

    public const MAX_QUANTITY = 1_000_000;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => $this->versionRules(),
            'lines' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            // Entiers JSON stricts : ni « 12 », ni 12.5, ni true.
            'lines.*.product_id' => ['required', 'integer:strict', 'min:1'],
            'lines.*.validated_qty' => ['required', 'integer:strict', 'min:0', 'max:'.self::MAX_QUANTITY],
            'lines.*.reason_code' => ['nullable', 'string', new ActiveReasonCode(ReasonType::Adjustment)],
            'lines.*.comment' => ['nullable', 'string', 'max:'.OrderDecisions::ADJUSTMENT_COMMENT_MAX],
        ];
    }

    /**
     * Un produit au plus une fois par décision : l'erreur porte sur la
     * seconde occurrence (les violations d'OrderDecisions portent les mêmes
     * indices).
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
                    $validator->errors()->add("lines.{$index}.product_id", __('orders.regional_decision.duplicate_line'));

                    continue;
                }
                $seen[$line['product_id']] = true;
            }
        }];
    }

    /**
     * Lignes validées, dans l'ordre reçu (OrderDecisions numérote ses erreurs
     * et ses violations d'après cet ordre).
     *
     * @return list<array{product_id: int, validated_qty: int, reason_code: ?string, comment: ?string}>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'validated_qty' => $line['validated_qty'],
            'reason_code' => $line['reason_code'] ?? null,
            'comment' => $line['comment'] ?? null,
        ], $this->validated('lines')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $quantity = __('orders.regional_decision.quantity_format', ['max' => number_format(self::MAX_QUANTITY, 0, ',', ' ')]);

        return [
            ...parent::messages(),
            'list' => __('orders.regional_decision.lines_format', ['max' => self::MAX_LINES]),
            'lines.min' => __('orders.regional_decision.lines_format', ['max' => self::MAX_LINES]),
            'lines.max' => __('orders.regional_decision.lines_format', ['max' => self::MAX_LINES]),
            'lines.*.validated_qty.integer' => $quantity,
            'lines.*.validated_qty.min' => $quantity,
            'lines.*.validated_qty.max' => $quantity,
            'lines.*.comment.max' => __('reasons.comment_max', ['max' => OrderDecisions::ADJUSTMENT_COMMENT_MAX]),
        ];
    }
}
