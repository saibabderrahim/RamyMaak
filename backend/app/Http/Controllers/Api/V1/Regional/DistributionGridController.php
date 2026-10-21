<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Limits\ShowBackOfficeGridRequest;
use App\Http\Requests\Limits\ShowDistributionGridRequest;
use App\Http\Resources\DistributionGridResource;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\User;

/**
 * Grille de répartition produit × client d'une région pour une journée
 * (EF-REG-02, EF-REG-03 ; contrat du lot 4, §3) : le régional la lit pour
 * saisir ses bornes, l'ADV et l'Admin la lisent sans saisie. Rôle,
 * permission et périmètre à la date de la journée sont contrôlés par les
 * requêtes (403, puis 404 hors périmètre).
 */
class DistributionGridController extends Controller
{
    /** GET /regional/days/{day}/grid?region={id} */
    public function show(ShowDistributionGridRequest $request, BusinessDay $businessDay): DistributionGridResource
    {
        /** @var User $user */
        $user = $request->user();

        return DistributionGridResource::for($request->regionDay(), $user);
    }

    /**
     * GET /adv/days/{day}/regions/{region}/grid : lecture seule. La région
     * liée par la route est contrôlée par la requête (active, périmètre).
     */
    public function showForBackOffice(ShowBackOfficeGridRequest $request, BusinessDay $businessDay, Region $region): DistributionGridResource
    {
        /** @var User $user */
        $user = $request->user();

        return DistributionGridResource::for($request->regionDay(), $user);
    }
}
