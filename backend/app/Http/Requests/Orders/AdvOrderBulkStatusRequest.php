<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Changement de statut en masse (RG-A02, I-78 ; contrat du lot 6, §5) :
 * `order.process`, ADV. Corps : `status` parmi En préparation, Préparée,
 * Expédiée (une seule étape à la fois ; Livrée, V2, est refusée) ; 1 à 100
 * commandes distinctes `{id, version}`, ULID bien formés, version entière
 * JSON stricte. Le périmètre et l'état de chaque commande sont jugés par
 * AdvOrders::transition, élément par élément (résultat partiel) : aucune
 * cible unique ici, donc pas de 404.
 */
class AdvOrderBulkStatusRequest extends AdvOrderRequest
{
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
            'status' => ['required', 'string', Rule::in(OrderStatus::values(AdvOrders::processingStatuses()))],
            'orders' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.AdvOrders::MAX_BULK],
            'orders.*' => ['required', 'array'],
            'orders.*.id' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Order::isPublicId($value)) {
                    $fail(__('orders.adv_request.order_id_format'));
                }
            }],
            'orders.*.version' => ['required', 'integer:strict', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'list' => __('validation.array'),
            'status.in' => __('orders.adv.invalid_status'),
            'orders.max' => __('orders.adv.too_many', ['max' => AdvOrders::MAX_BULK]),
        ];
    }

    /**
     * Commandes distinctes (casse indifférente, I-56) : l'erreur porte sur
     * la seconde occurrence.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $orders = $this->input('orders');
            if (! is_array($orders)) {
                return;
            }

            $seen = [];
            foreach ($orders as $index => $order) {
                $id = is_array($order) ? ($order['id'] ?? null) : null;
                if (! is_string($id) || ! Order::isPublicId($id)) {
                    continue;
                }
                $key = strtoupper($id);
                if (isset($seen[$key])) {
                    $validator->errors()->add("orders.{$index}.id", __('orders.adv_request.duplicate_order'));

                    continue;
                }
                $seen[$key] = true;
            }
        }];
    }

    public function status(): OrderStatus
    {
        return OrderStatus::from((string) $this->validated('status'));
    }

    /**
     * Commandes à traiter, dans l'ordre reçu.
     *
     * @return list<array{id: string, version: int}>
     */
    public function items(): array
    {
        return array_values(array_map(fn (array $order) => [
            'id' => $order['id'],
            'version' => (int) $order['version'],
        ], $this->validated('orders')));
    }
}
