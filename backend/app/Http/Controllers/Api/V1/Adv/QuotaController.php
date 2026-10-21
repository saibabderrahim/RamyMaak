<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotas\CopyQuotasRequest;
use App\Http\Requests\Quotas\PublishQuotasRequest;
use App\Http\Requests\Quotas\SaveQuotaDraftsRequest;
use App\Http\Resources\QuotaMatrixResource;
use App\Models\BusinessDay;
use App\Models\RegionQuota;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Matrice des quotas d'une journée (EF-ADV-02, §5.2) : lecture, brouillons,
 * copie depuis une autre journée et publication. Toute écriture passe par
 * QuotaBook, qui applique le périmètre de l'ADV (RG-S05), les règles RG-Q01
 * à RG-Q08 et lève DAY_NOT_OPEN, QUOTA_BELOW_VALIDATED et les erreurs de
 * validation par cellule.
 */
class QuotaController extends Controller
{
    public function __construct(
        private readonly QuotaBook $book,
        private readonly DayCalendar $calendar,
    ) {}

    public function index(Request $request, BusinessDay $businessDay): QuotaMatrixResource
    {
        Gate::authorize('viewMatrix', RegionQuota::class);

        /** @var User $user */
        $user = $request->user();

        return QuotaMatrixResource::for($businessDay, $user);
    }

    /** Enregistrement de cellules en brouillon (RG-Q03) ; renvoie leur état après coup. */
    public function update(SaveQuotaDraftsRequest $request, BusinessDay $businessDay): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cells = $request->cells();
        $changed = $this->book->saveDrafts($businessDay, $cells, $user);

        return response()->json(['changed' => $changed, 'cells' => $this->states($businessDay, $cells)]);
    }

    /** « Copier depuis la journée… » : quotas publiés de la source, en brouillon. */
    public function copy(CopyQuotasRequest $request, BusinessDay $businessDay): JsonResponse
    {
        // Journée cible clôturée : refusée même quand la source n'a rien à copier.
        $this->calendar->assertOpen($businessDay);

        /** @var User $user */
        $user = $request->user();
        $changed = $this->book->copyFrom($businessDay, $request->sourceDay(), $request->regionIds(), $request->replaces(), $user);

        return response()->json(['changed' => $changed]);
    }

    /** Publication par sélection de régions ou pour toutes les régions (RG-Q04). */
    public function publish(PublishQuotasRequest $request, BusinessDay $businessDay): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->book->publish($businessDay, $request->regionIds(), $user)->toArray());
    }

    /**
     * État des cellules envoyées, dans l'ordre reçu ; une cellule sans ligne
     * en base vaut 0, sans brouillon ni validé.
     *
     * @param  list<array{region_id: int, product_id: int, quantity: ?int}>  $cells
     * @return list<array{region_id: int, product_id: int, quantity: int, draft: ?int, validated: int}>
     */
    private function states(BusinessDay $day, array $cells): array
    {
        $rows = RegionQuota::query()
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->where('region_days.business_day_id', $day->id)
            ->whereIn('region_days.region_id', array_values(array_unique(array_column($cells, 'region_id'))))
            ->whereIn('region_quotas.product_id', array_values(array_unique(array_column($cells, 'product_id'))))
            ->toBase()
            ->get(['region_days.region_id', 'region_quotas.product_id', 'region_quotas.quantity', 'region_quotas.draft_quantity', 'region_quotas.validated_qty'])
            ->keyBy(fn (object $row) => $row->region_id.'|'.$row->product_id);

        return array_map(function (array $cell) use ($rows): array {
            $row = $rows->get($cell['region_id'].'|'.$cell['product_id']);

            return [
                'region_id' => $cell['region_id'],
                'product_id' => $cell['product_id'],
                'quantity' => (int) ($row->quantity ?? 0),
                'draft' => $row?->draft_quantity === null ? null : (int) $row->draft_quantity,
                'validated' => (int) ($row->validated_qty ?? 0),
            ];
        }, $cells);
    }
}
