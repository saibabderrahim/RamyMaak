<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Domain\Orders\AdvOrders;
use App\Domain\Reasons\ReasonType;
use App\Rules\ActiveReasonCode;

/**
 * Expédition d'une commande (RG-A03, EF-ADV-06 ; contrat du lot 6, §5) :
 * `order.process`, ADV. Corps : `version` (entier JSON strict) ; `lines`
 * facultatif, tout ou partie des lignes `{product_id, shipped_qty,
 * reason_code?}` ; `delivery_note_ref` facultatif, nullable, 50 caractères
 * au plus (null ou vide l'efface).
 *
 * La forme seule est contrôlée ici ; le sens (statut, version, produit de la
 * commande, 0 ≤ expédié ≤ validé, motif d'écart si et seulement si expédié
 * < validé) l'est par AdvOrders::updateShipping, sous les verrous.
 */
class AdvOrderShippingRequest extends AdvOrderTargetRequest
{
    public const MAX_LINES = 200;

    public const MAX_QUANTITY = 1_000_000;

    protected function permission(): Permission
    {
        return Permission::OrderProcess;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer:strict', 'min:1'],
            'delivery_note_ref' => ['nullable', 'string', 'max:'.AdvOrders::DELIVERY_NOTE_MAX],
            'lines' => ['nullable', 'array', 'list', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.product_id' => ['required', 'integer:strict'],
            'lines.*.shipped_qty' => ['required', 'integer:strict', 'min:0', 'max:'.self::MAX_QUANTITY],
            'lines.*.reason_code' => ['nullable', 'string', new ActiveReasonCode(ReasonType::ShippingGap)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'list' => __('validation.array'),
            'delivery_note_ref.max' => __('orders.adv.delivery_note_max', ['max' => AdvOrders::DELIVERY_NOTE_MAX]),
        ];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    /**
     * Lignes transmises, dans l'ordre reçu (les erreurs du service portent
     * les mêmes indices) ; null si `lines` est absent.
     *
     * @return list<array{product_id: int, shipped_qty: int, reason_code: ?string}>|null
     */
    public function lines(): ?array
    {
        $lines = $this->validated('lines');
        if (! is_array($lines)) {
            return null;
        }

        return array_values(array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'shipped_qty' => $line['shipped_qty'],
            'reason_code' => $line['reason_code'] ?? null,
        ], $lines));
    }

    /** Bon de livraison transmis (même null) : null ou vide l'efface. */
    public function deliveryNoteGiven(): bool
    {
        return $this->has('delivery_note_ref');
    }

    public function deliveryNoteRef(): ?string
    {
        $ref = $this->validated('delivery_note_ref');

        return is_string($ref) ? $ref : null;
    }
}
