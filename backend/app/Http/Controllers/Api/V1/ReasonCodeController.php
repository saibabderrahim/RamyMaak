<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

/**
 * Motifs actifs (EF-ADM-05 ; contrat du lot 6, §4) : GET /reason-codes,
 * avec ReasonCodes.
 *
 * Socle du lot 6 : contrôleur provisoire (501), remplacé par son volet ;
 * nom de classe et méthodes fixés par les routes.
 */
class ReasonCodeController extends Controller
{
    public function index(): never
    {
        abort(501);
    }
}
