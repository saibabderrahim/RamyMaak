<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Http\Controllers\Controller;

/**
 * « Ma journée » du régional (EF-REG-01 ; contrat du lot 6, §3) :
 * GET /regional/dashboard.
 *
 * Socle du lot 6 : contrôleur provisoire (501), remplacé par son volet ;
 * nom de classe et méthodes fixés par les routes.
 */
class RegionalDashboardController extends Controller
{
    public function show(): never
    {
        abort(501);
    }
}
