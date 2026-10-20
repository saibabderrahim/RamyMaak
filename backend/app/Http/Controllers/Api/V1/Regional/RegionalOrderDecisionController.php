<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Http\Controllers\Controller;

/**
 * Décision du régional sur une commande envoyée (contrat du lot 6, §3) :
 * POST /regional/orders/{order}/validate et /reject, avec OrderDecisions.
 *
 * Socle du lot 6 : contrôleur provisoire (501), remplacé par son volet ;
 * nom de classe et méthodes fixés par les routes.
 */
class RegionalOrderDecisionController extends Controller
{
    public function validate(string $order): never
    {
        abort(501);
    }

    public function reject(string $order): never
    {
        abort(501);
    }
}
