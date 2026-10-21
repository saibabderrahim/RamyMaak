<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Domain\Orders\DayMonitor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Days\DayMonitoringRequest;
use App\Http\Resources\DayResource;
use App\Models\BusinessDay;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Suivi de la journée (EF-ADV-03 ; contrat du lot 6, §6) : réponse sans
 * enveloppe `data` (I-96), calculée par DayMonitor sur les régions du
 * périmètre du lecteur (RG-S05), en un nombre de requêtes indépendant du
 * nombre de commandes.
 */
class DayMonitoringController extends Controller
{
    public function __construct(private readonly DayMonitor $monitor) {}

    /** GET /adv/days/{day}/monitoring */
    public function show(DayMonitoringRequest $request, BusinessDay $businessDay): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->monitor->snapshot($businessDay, DayResource::visibleRegionDays($businessDay, $user)));
    }
}
