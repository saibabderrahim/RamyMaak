<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Domain\Orders\OrderDecisions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\RegionalRejectOrderRequest;
use App\Http\Requests\Orders\RegionalValidateOrderRequest;
use App\Http\Resources\Orders\RegionalOrderResource;
use App\Models\User;

/**
 * Décision du régional sur une commande envoyée (contrat du lot 6, §3) :
 * POST /regional/orders/{order}/validate et /reject, avec OrderDecisions.
 * Droits, périmètre à la journée de la commande et forme sont contrôlés par
 * les requêtes (403, puis 404, puis 422) ; le reste, sous verrou, par le
 * service, dont les refus parviennent tels quels (Problem Details et leurs
 * membres : version, cutoff, violations). Réponse : le détail régional de
 * la commande décidée (200), y compris pour un renvoi de la même décision
 * (I-92).
 */
class RegionalOrderDecisionController extends Controller
{
    public function __construct(private readonly OrderDecisions $decisions) {}

    /** POST /regional/orders/{order}/validate : avec ou sans ajustement (RG-V02 à RG-V05). */
    public function validate(RegionalValidateOrderRequest $request, string $order): RegionalOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $this->decisions->validate($user, $request->order(), $request->version(), $request->lines());

        return RegionalOrderResource::for($validated);
    }

    /** POST /regional/orders/{order}/reject : jamais bloqué par l'heure limite ni par le statut du client (I-70, I-71). */
    public function reject(RegionalRejectOrderRequest $request, string $order): RegionalOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        $rejected = $this->decisions->reject($user, $request->order(), $request->version(), $request->reasonCode(), $request->comment());

        return RegionalOrderResource::for($rejected);
    }
}
