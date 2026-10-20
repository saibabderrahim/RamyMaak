<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reasons\ReasonCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reasons\ListReasonCodesRequest;
use App\Http\Resources\Reasons\ReasonOptionResource;
use Illuminate\Http\JsonResponse;

/**
 * Motifs actifs (EF-ADM-05 ; contrat du lot 6, §4) : GET /reason-codes,
 * listes de choix du régional (ajustement, rejet), de l'ADV (écart
 * d'expédition, annulation) et de l'Admin. Le rôle est contrôlé par la
 * requête (403), les motifs lus par ReasonCodes.
 */
class ReasonCodeController extends Controller
{
    /**
     * GET /reason-codes?type=ADJUSTMENT[,REJECTION] : non paginée, par type
     * (ordre alphabétique des types, comme la base), puis ordre, puis
     * libellé ; motifs désactivés exclus.
     */
    public function index(ListReasonCodesRequest $request, ReasonCodes $reasons): JsonResponse
    {
        $types = $request->types();
        usort($types, fn ($a, $b) => strcmp($a->value, $b->value));

        $active = [];
        foreach ($types as $type) {
            array_push($active, ...$reasons->active($type));
        }

        return response()->json(['data' => ReasonOptionResource::collection($active)]);
    }
}
