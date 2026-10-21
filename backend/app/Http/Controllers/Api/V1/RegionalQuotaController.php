<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quotas\RegionalQuotasRequest;
use App\Http\Resources\RegionalQuotasResource;
use App\Models\User;

/**
 * « Quotas du jour » du régional (§7.6, EF-REG-01) : quotas publiés des
 * régions de son périmètre à la date demandée (aujourd'hui par défaut,
 * demain pour anticiper), jamais les brouillons de l'ADV (RG-Q03).
 */
class RegionalQuotaController extends Controller
{
    public function index(RegionalQuotasRequest $request): RegionalQuotasResource
    {
        /** @var User $user */
        $user = $request->user();

        return RegionalQuotasResource::for($request->day(), $user);
    }
}
