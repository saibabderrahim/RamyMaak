<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Http\Controllers\Controller;

/**
 * Documents de la journée (RG-A04 ; contrat du lot 6, §6) : bon de
 * préparation et export des commandes.
 *
 * Socle du lot 6 : contrôleur provisoire (501), remplacé par son volet ;
 * nom de classe et méthodes fixés par les routes.
 */
class DayDocumentController extends Controller
{
    public function pickingList(): never
    {
        abort(501);
    }

    public function export(): never
    {
        abort(501);
    }
}
