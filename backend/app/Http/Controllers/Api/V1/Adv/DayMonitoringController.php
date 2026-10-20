<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Http\Controllers\Controller;

/**
 * Suivi de la journée (EF-ADV-03 ; contrat du lot 6, §6).
 *
 * Socle du lot 6 : contrôleur provisoire (501), remplacé par son volet ;
 * nom de classe et méthodes fixés par les routes.
 */
class DayMonitoringController extends Controller
{
    public function show(): never
    {
        abort(501);
    }
}
