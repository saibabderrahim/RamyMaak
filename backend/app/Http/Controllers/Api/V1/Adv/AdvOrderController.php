<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Http\Controllers\Controller;

/**
 * File des commandes validées de l'ADV (EF-ADV-04 ; contrat du lot 6, §5) :
 * liste, détail, statuts en masse, expédition, annulation, bon de
 * commande, avec AdvOrders.
 *
 * Socle du lot 6 : contrôleur provisoire (501), remplacé par son volet ;
 * nom de classe et méthodes fixés par les routes.
 */
class AdvOrderController extends Controller
{
    public function index(): never
    {
        abort(501);
    }

    public function show(string $order): never
    {
        abort(501);
    }

    public function bulkStatus(): never
    {
        abort(501);
    }

    public function shipping(string $order): never
    {
        abort(501);
    }

    public function cancel(string $order): never
    {
        abort(501);
    }

    public function pdf(string $order): never
    {
        abort(501);
    }
}
