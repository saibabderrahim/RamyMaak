<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;

/**
 * Motifs paramétrables par l'Admin (EF-ADM-05 ; contrat du lot 6, §4) :
 * liste, création, modification, avec ReasonCodeBook.
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

    public function store(): never
    {
        abort(501);
    }

    public function update(string $reasonCode): never
    {
        abort(501);
    }
}
