<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Domain\Days\DayCalendar;
use App\Domain\Limits\ClientUsage;
use App\Domain\Limits\LimitBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Limits\CopyPreviousLimitsRequest;
use App\Http\Requests\Limits\DistributionRequest;
use App\Http\Requests\Limits\PublishDistributionRequest;
use App\Http\Requests\Limits\SaveLimitDraftsRequest;
use App\Http\Resources\DistributionGridResource;
use App\Models\BusinessDay;
use App\Models\ClientLimit;
use App\Models\RegionDay;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Bornes client du régional (§5.3 ; contrat du lot 4, §3) : brouillons
 * enregistrés à chaque saisie (RG-B09), « Reprendre J-1 » (RG-B08), envoi
 * aux clients (RG-B06, RG-B07). Toute écriture passe par LimitBook, qui
 * applique RG-B01 à RG-B08 et lève DAY_NOT_OPEN, INVALID_TRANSITION,
 * VALIDATION_FAILED et QUOTA_BELOW_VALIDATED (avec `violations`) et les
 * erreurs de validation par cellule.
 */
class LimitController extends Controller
{
    public function __construct(
        private readonly LimitBook $book,
        private readonly DayCalendar $calendar,
        private readonly ClientUsage $usage,
    ) {}

    /** Enregistrement de bornes en brouillon ; renvoie le statut de la région et l'état des cellules envoyées. */
    public function update(SaveLimitDraftsRequest $request, BusinessDay $businessDay): JsonResponse
    {
        $regionDay = $this->regionDay($request);
        $cells = $request->cells();
        $changed = $this->book->saveDrafts($regionDay, $cells, $this->user($request));

        return response()->json([
            'changed' => $changed,
            'status' => $regionDay->refresh()->status->value,
            'cells' => $this->states($regionDay, $cells),
        ]);
    }

    /** « Reprendre J-1 » : écraser ou compléter les cases vides, en brouillon. */
    public function copyPrevious(CopyPreviousLimitsRequest $request, BusinessDay $businessDay): JsonResponse
    {
        return response()->json($this->book->copyPrevious($this->regionDay($request), $request->overwrites(), $this->user($request)));
    }

    /** « Valider et envoyer aux clients » : version, bornes modifiées, clients notifiés, surallocations. */
    public function publish(PublishDistributionRequest $request, BusinessDay $businessDay): JsonResponse
    {
        return response()->json($this->book->publish($this->regionDay($request), $this->user($request))->toArray());
    }

    /** Journée régionale à modifier ; journée clôturée : 409 sans créer de journée régionale. */
    private function regionDay(DistributionRequest $request): RegionDay
    {
        $this->calendar->assertOpen($request->businessDay());

        return $request->regionDay();
    }

    private function user(DistributionRequest $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * État des cellules envoyées, dans l'ordre reçu ; sans ligne en base :
     * pas de borne, pas de brouillon.
     *
     * @param  list<array{client_id: int, product_id: int, limit: ?int}>  $cells
     * @return list<array<string, mixed>>
     */
    private function states(RegionDay $regionDay, array $cells): array
    {
        $rows = ClientLimit::query()
            ->where('region_day_id', $regionDay->id)
            ->whereIn('client_id', array_values(array_unique(array_column($cells, 'client_id'))))
            ->whereIn('product_id', array_values(array_unique(array_column($cells, 'product_id'))))
            ->get(['client_id', 'product_id', 'max_qty', 'draft_max_qty', 'has_draft'])
            ->keyBy(fn (ClientLimit $limit) => $limit->client_id.'|'.$limit->product_id);
        $usage = $this->usage->forRegionDay($regionDay);

        return array_map(fn (array $cell) => [
            'client_id' => $cell['client_id'],
            'product_id' => $cell['product_id'],
            ...DistributionGridResource::cell(
                $rows->get($cell['client_id'].'|'.$cell['product_id']),
                $usage[$cell['client_id']][$cell['product_id']] ?? null,
            ),
        ], $cells);
    }
}
