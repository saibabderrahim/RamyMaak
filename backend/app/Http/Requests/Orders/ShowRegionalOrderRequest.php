<?php

namespace App\Http\Requests\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use LogicException;

/**
 * Détail d'une commande reçue (EF-REG-04, EF-REG-05 sans décision) :
 * `{order}`, ULID non lié par la route. Après les droits : identifiant mal
 * formé → 404 sans requête ; commande inconnue, ou dont la région n'est pas
 * dans le périmètre du régional **à la date de la commande** → 404.
 */
class ShowRegionalOrderRequest extends RegionalOrderRequest
{
    private ?Order $resolvedOrder = null;

    protected function authorizeTarget(User $user): Response
    {
        $id = $this->route('order');
        // I-56 : ULID en majuscules, recherche insensible à la casse.
        $order = is_string($id) && Order::isPublicId($id)
            ? Order::query()->with('businessDay')->where('public_id', strtoupper($id))->first()
            : null;
        // Région figée à l'envoi, périmètre de la journée de la commande (§3.3, RG-S04), non d'aujourd'hui.
        if ($order === null || ! $this->perimeter()->canAccessRegion($user, $order->region_id, $order->businessDay->day)) {
            return Response::denyAsNotFound(__('orders.not_found'));
        }
        $this->resolvedOrder = $order;

        return Response::allow();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /** Commande contrôlée par authorize(). */
    public function order(): Order
    {
        return $this->resolvedOrder ?? throw new LogicException('Commande non résolue.');
    }
}
