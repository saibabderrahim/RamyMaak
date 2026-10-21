<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Perimeter;
use App\Domain\Orders\AdvOrders;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use LogicException;

/**
 * Requête sur une commande de la file ADV désignée par `{order}` (ULID non
 * lié par la route) : identifiant mal formé → 404 sans requête ; commande
 * inconnue, hors de la file (envoyée, rejetée, expirée) ou dont la région
 * n'est pas dans le périmètre de l'appelant **à la date de la commande**
 * (RG-S05, I-90) → 404, toujours avant la validation du corps.
 */
abstract class AdvOrderTargetRequest extends AdvOrderRequest
{
    private ?Order $resolvedOrder = null;

    protected function authorizeTarget(User $user): Response
    {
        $id = $this->route('order');
        // I-56 : ULID en majuscules, recherche insensible à la casse.
        $order = is_string($id) && Order::isPublicId($id)
            ? AdvOrders::visible(app(Perimeter::class)->constrainOrders(Order::query(), $user))
                ->where('orders.public_id', strtoupper($id))
                ->first()
            : null;
        if ($order === null) {
            return Response::denyAsNotFound(__('orders.not_found'));
        }
        $this->resolvedOrder = $order;

        return Response::allow();
    }

    /** Commande contrôlée par authorize(). */
    public function order(): Order
    {
        return $this->resolvedOrder ?? throw new LogicException('Commande non résolue.');
    }
}
