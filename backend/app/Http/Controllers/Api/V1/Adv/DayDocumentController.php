<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Domain\Documents\OrderExport;
use App\Domain\Documents\PickingList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\OrderExportRequest;
use App\Http\Requests\Documents\PickingListRequest;
use App\Models\BusinessDay;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents de la journée (RG-A04 ; contrat du lot 6, §6) : bon de
 * préparation consolidé (PDF ou XLSX) et export des commandes (XLSX, CSV,
 * ERP). Droits, périmètre (RG-S05) et forme sont contrôlés par les requêtes
 * (403, puis 404, puis 422) ; le contenu et la mise en forme par
 * `App\Domain\Documents`. Téléchargements synchrones, en flux, sans fichier
 * conservé, `Cache-Control: no-store` (I-83).
 */
class DayDocumentController extends Controller
{
    /** GET /adv/days/{day}/picking-list */
    public function pickingList(PickingListRequest $request, BusinessDay $businessDay, PickingList $pickingList): Response
    {
        $report = $pickingList->build($businessDay, $request->statuses(), $request->group(), $request->regionIds());

        return $pickingList->response($report, $request->fileFormat());
    }

    /** GET /adv/days/{day}/orders/export : au-delà de 100 000 lignes, 422 sur `filter`. */
    public function export(OrderExportRequest $request, BusinessDay $businessDay, OrderExport $export): Response
    {
        return $export->response($businessDay, $request->fileFormat(), $request->statuses(), $request->regionIds());
    }
}
