<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Domain\Orders\RegionalDayOverview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\RegionalDashboardRequest;
use Illuminate\Http\JsonResponse;

/**
 * « Ma journée » du régional (EF-REG-01 ; contrat du lot 6, §3) :
 * GET /regional/dashboard?day=&region=. Droits et périmètre sont contrôlés
 * par la requête (403, 404, 422) ; la lecture, par RegionalDayOverview.
 * Réponse sans enveloppe `data` (I-96).
 */
class RegionalDashboardController extends Controller
{
    public function show(RegionalDashboardRequest $request, RegionalDayOverview $overview): JsonResponse
    {
        return response()->json($overview->read($request->day(), $request->regionIds()));
    }
}
