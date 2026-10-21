<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use LogicException;

/**
 * Décision du régional sur une commande (contrat du lot 6, §2 et §3) :
 * validation et rejet. Contrôlés avant la validation du corps (401, 403,
 * 404, puis 422) : rôle Régional et permission `order.validate` (l'ADV,
 * l'Admin et le client reçoivent 403) ; `{order}`, ULID non lié par la
 * route, mal formé (404 sans requête), inconnu, ou dont la région (figée à
 * l'envoi) n'est pas dans le périmètre du régional **à la date de la
 * commande** (titulaire ou suppléant, §3.4) → 404.
 *
 * Forme seulement (422) : `version` ; transition, version, journée, heure
 * limite, client et sens des décisions sont contrôlés par OrderDecisions,
 * sous verrou (I-68).
 */
abstract class RegionalDecisionRequest extends RegionalOrderRequest
{
    private ?Order $resolvedOrder = null;

    protected function permission(): Permission
    {
        return Permission::OrderValidate;
    }

    protected function authorizeTarget(User $user): Response
    {
        $id = $this->route('order');
        // I-56 : ULID en majuscules, recherche insensible à la casse.
        $order = is_string($id) && Order::isPublicId($id)
            ? Order::query()->with('businessDay')->where('public_id', strtoupper($id))->first()
            : null;
        // Région figée à l'envoi, périmètre de la journée de la commande (§3.3, RG-S04).
        if ($order === null || ! $this->perimeter()->canAccessRegion($user, $order->region_id, $order->businessDay->day)) {
            return Response::denyAsNotFound(__('orders.not_found'));
        }
        $this->resolvedOrder = $order;

        return Response::allow();
    }

    /** `version` affichée : entier JSON strict (RG-V06). */
    protected function versionRules(): array
    {
        return ['required', 'integer:strict', 'min:1'];
    }

    /** Commande désignée, contrôlée par authorize(). */
    public function order(): Order
    {
        return $this->resolvedOrder ?? throw new LogicException('Commande non résolue.');
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('orders.decision.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $version = __('orders.regional_decision.version_format');

        return [
            'version.integer' => $version,
            'version.min' => $version,
        ];
    }
}
