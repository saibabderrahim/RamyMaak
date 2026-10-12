<?php

namespace App\Domain\Limits;

use App\Domain\Access\Perimeter;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Referentials\ClientStatus;
use App\Domain\Settings\Settings;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bornes client et répartition d'une région pour une journée (§5.3,
 * RG-B01 à RG-B09) : brouillon enregistré à chaque saisie, « Reprendre
 * J-1 », envoi aux clients. Les règles sont appliquées ici, quel que soit
 * l'écran ou l'import d'origine.
 */
final class LimitBook
{
    /** Valeur maximale d'une borne (comme un quota, en UV). */
    public const MAX_LIMIT = 1_000_000;

    /** Entrées « changes » gardées dans le journal d'une publication. */
    private const AUDIT_CHANGES = 200;

    public function __construct(
        private readonly DayCalendar $calendar,
        private readonly Perimeter $perimeter,
        private readonly ClientUsage $usage,
        private readonly Settings $settings,
    ) {}

    /**
     * Clients de la région ce jour-là (région effective, RG-S04), actifs ou
     * bloqués (un client bloqué peut être débloqué dans la journée), par code.
     *
     * @return Builder<Client>
     */
    public function clientsQuery(RegionDay $regionDay): Builder
    {
        return Client::query()
            ->inEffectiveRegions([$regionDay->region_id], $regionDay->businessDay->day)
            ->whereIn('status', [ClientStatus::Active->value, ClientStatus::Blocked->value])
            ->orderBy('code');
    }

    /**
     * Produits disponibles (RG-B02) : quota publié > 0 dans la région, produit
     * actif ce jour-là (RG-Q02, RG-Q07).
     *
     * @return array<int, int> produit → quota publié Q(r,p)
     */
    public function availableQuotas(RegionDay $regionDay): array
    {
        $day = $regionDay->businessDay->day;

        return RegionQuota::query()
            ->where('region_day_id', $regionDay->id)
            ->where('quantity', '>', 0)
            ->whereHas('product', fn (Builder $product) => $product->activeOn($day))
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }

    /**
     * Enregistre des bornes en brouillon (RG-B09). `limit` : entier de 0 au
     * quota de la région (RG-B01, RG-B03), ou null pour « pas de borne ».
     * Une valeur égale à la borne publiée annule le brouillon.
     *
     * @param  list<array{client_id: int, product_id: int, limit: ?int}>  $cells
     * @return int cellules dont le brouillon a changé
     *
     * @throws ApiException DAY_NOT_OPEN (journée clôturée, quotas non publiés)
     * @throws ValidationException cellule invalide
     */
    public function saveDrafts(RegionDay $regionDay, array $cells, User $by): int
    {
        if ($cells === []) {
            return 0;
        }

        return DB::transaction(function () use ($regionDay, $cells, $by): int {
            $regionDay = $this->lockForWriting($regionDay, $by);
            $quotas = $this->availableQuotas($regionDay);
            $clientIds = $this->clientsQuery($regionDay)
                ->whereKey(array_unique(array_column($cells, 'client_id')))
                ->pluck('id')
                ->flip();
            $usage = $regionDay->isDistributionPublished() ? $this->usage->forRegionDay($regionDay) : [];

            $errors = [];
            foreach ($cells as $index => $cell) {
                if (! $clientIds->has($cell['client_id'])) {
                    $errors["cells.{$index}.client_id"] = [__('limits.client_not_in_region')];
                }
                $quota = $quotas[$cell['product_id']] ?? null;
                if ($quota === null) {
                    $errors["cells.{$index}.product_id"] = [__('limits.product_unavailable')];

                    continue;
                }
                $limit = $cell['limit'];
                if ($limit !== null && $limit > $quota) {
                    $errors["cells.{$index}.limit"] = [__('limits.above_quota', ['quota' => $quota])];
                }
                $validated = $usage[$cell['client_id']][$cell['product_id']]['validated'] ?? 0;
                if ($limit !== null && $limit < $validated) {
                    $errors["cells.{$index}.limit"] = [__('limits.below_validated', ['validated' => $validated])];
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $existing = ClientLimit::query()
                ->where('region_day_id', $regionDay->id)
                ->whereIn('client_id', array_unique(array_column($cells, 'client_id')))
                ->whereIn('product_id', array_unique(array_column($cells, 'product_id')))
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ClientLimit $limit) => $limit->client_id.'|'.$limit->product_id);

            $changed = 0;
            foreach ($cells as $cell) {
                $key = $cell['client_id'].'|'.$cell['product_id'];
                $row = $existing->get($key) ?? new ClientLimit([
                    'region_day_id' => $regionDay->id,
                    'client_id' => $cell['client_id'],
                    'product_id' => $cell['product_id'],
                ]);

                // Valeur égale à la borne publiée : plus rien à envoyer.
                $hasDraft = $cell['limit'] !== $row->max_qty;
                $draft = $hasDraft ? $cell['limit'] : null;
                if ($row->exists && $row->has_draft === $hasDraft && $row->draft_max_qty === $draft) {
                    continue;
                }
                if (! $row->exists && ! $hasDraft) {
                    continue;
                }

                $row->has_draft = $hasDraft;
                $row->draft_max_qty = $draft;
                $row->updated_by = $by->id;
                $row->save();
                $existing->put($key, $row);
                $changed++;
            }

            if ($changed > 0) {
                $statusChanged = $regionDay->status === RegionDayStatus::QuotaPublished;
                if ($statusChanged) {
                    $regionDay->status = RegionDayStatus::DistributionDraft;
                    $regionDay->save();
                }
                event(new LimitDraftsSaved($regionDay->id, $by->id, $statusChanged));
            }

            return $changed;
        });
    }

    /**
     * « Reprendre J-1 » (RG-B08) : bornes publiées de la dernière journée où
     * la répartition de la région a été envoyée, pour les produits
     * disponibles ce jour, plafonnées au quota du jour. Écraser : chaque
     * cellule prend la valeur reprise (pas de borne si absente) ; Compléter :
     * seules les cellules sans borne ni brouillon sont renseignées.
     *
     * @return array{changed: int, source_day: string}
     */
    public function copyPrevious(RegionDay $target, bool $overwrite, User $by): array
    {
        $this->lockForWriting($target, $by, lock: false);
        $day = $target->businessDay->day;

        $source = RegionDay::query()
            ->with('businessDay')
            ->where('region_id', $target->region_id)
            ->where('distribution_version', '>', 0)
            ->whereHas('businessDay', fn (Builder $q) => $q->where('day', '<', $day->format('Y-m-d')))
            ->get()
            ->sortByDesc(fn (RegionDay $regionDay) => $regionDay->businessDay->day->format('Y-m-d'))
            ->first();
        if ($source === null) {
            throw ValidationException::withMessages(['source' => [__('limits.no_previous')]]);
        }

        $previous = ClientLimit::query()
            ->where('region_day_id', $source->id)
            ->whereNotNull('max_qty')
            ->get(['client_id', 'product_id', 'max_qty'])
            ->groupBy('client_id')
            ->map(fn (Collection $limits) => $limits->pluck('max_qty', 'product_id')->all());
        $current = ClientLimit::query()
            ->where('region_day_id', $target->id)
            ->get()
            ->keyBy(fn (ClientLimit $limit) => $limit->client_id.'|'.$limit->product_id);

        $cells = [];
        $quotas = $this->availableQuotas($target);
        foreach ($this->clientsQuery($target)->pluck('id') as $clientId) {
            foreach ($quotas as $productId => $quota) {
                $value = $previous->get($clientId)[$productId] ?? null;
                $value = $value === null ? null : min((int) $value, $quota);
                $displayed = $current->get($clientId.'|'.$productId)?->displayed();
                if (! $overwrite && ($displayed !== null || $value === null)) {
                    continue;
                }
                if ($overwrite && $value === $displayed) {
                    continue;
                }
                $cells[] = ['client_id' => $clientId, 'product_id' => $productId, 'limit' => $value];
            }
        }

        return [
            'changed' => $this->saveDrafts($target, $cells, $by),
            'source_day' => $source->businessDay->dayKey(),
        ];
    }

    /**
     * Envoie la répartition aux clients (RG-B06, RG-B07) : les brouillons
     * deviennent les bornes publiées, la version augmente, les clients sont
     * notifiés (N-03 à la première publication, N-04 ensuite aux seuls
     * clients impactés). Tout ou rien : une borne au-dessus du quota de la
     * région (diminué depuis la saisie) ou sous la quantité déjà validée
     * refuse l'ensemble.
     *
     * @throws ApiException DAY_NOT_OPEN, INVALID_TRANSITION (rien à envoyer),
     *                      VALIDATION_FAILED (au-dessus du quota), QUOTA_BELOW_VALIDATED
     */
    public function publish(RegionDay $regionDay, User $by): DistributionResult
    {
        return DB::transaction(function () use ($regionDay, $by): DistributionResult {
            $regionDay = $this->lockForWriting($regionDay, $by);
            $first = ! $regionDay->isDistributionPublished();
            $quotas = $this->availableQuotas($regionDay);
            $clientIds = $this->clientsQuery($regionDay)->pluck('id')->flip();

            $drafts = ClientLimit::query()
                ->with(['client:id,code', 'product:id,sku'])
                ->where('region_day_id', $regionDay->id)
                ->where('has_draft', true)
                ->lockForUpdate()
                ->get();

            // Brouillons devenus sans objet (client sorti de la région, produit
            // sans quota) : effacés, jamais publiés.
            [$applicable, $obsolete] = $drafts->partition(
                fn (ClientLimit $limit) => $clientIds->has($limit->client_id) && isset($quotas[$limit->product_id]),
            );
            if ($obsolete->isNotEmpty()) {
                ClientLimit::query()->whereKey($obsolete->modelKeys())->update(['has_draft' => false, 'draft_max_qty' => null]);
            }
            if (! $first && $applicable->isEmpty()) {
                throw new ApiException(ErrorCode::InvalidTransition, __('limits.nothing_to_publish'));
            }

            $usage = $this->usage->forRegionDay($regionDay);
            $above = [];
            $below = [];
            foreach ($applicable as $limit) {
                $new = $limit->draft_max_qty;
                $quota = $quotas[$limit->product_id];
                $validated = $usage[$limit->client_id][$limit->product_id]['validated'] ?? 0;
                $violation = [
                    'client_id' => $limit->client_id,
                    'client' => $limit->client->code,
                    'product_id' => $limit->product_id,
                    'sku' => $limit->product->sku,
                    'requested' => $new,
                ];
                if ($new !== null && $new > $quota) {
                    $above[] = $violation + ['quota' => $quota];
                }
                if ($new !== null && $new < $validated) {
                    $below[] = $violation + ['validated' => $validated];
                }
            }
            if ($above !== []) {
                throw new ApiException(ErrorCode::ValidationFailed, __('limits.publish_above_quota'), ['violations' => $above]);
            }
            if ($below !== []) {
                throw new ApiException(ErrorCode::QuotaBelowValidated, __('limits.publish_below_validated'), ['violations' => $below]);
            }

            $changes = [];
            $changedClients = [];
            foreach ($applicable as $limit) {
                $changes[] = [
                    'client_id' => $limit->client_id,
                    'client' => $limit->client->code,
                    'product_id' => $limit->product_id,
                    'sku' => $limit->product->sku,
                    'old' => $limit->max_qty,
                    'new' => $limit->draft_max_qty,
                ];
                $changedClients[$limit->client_id] = true;
                $limit->forceFill([
                    'max_qty' => $limit->draft_max_qty,
                    'draft_max_qty' => null,
                    'has_draft' => false,
                    'updated_by' => $by->id,
                ])->save();
            }

            $regionDay->distribution_version++;
            if ($first) {
                $regionDay->distribution_published_at = now();
                $regionDay->distribution_published_by = $by->id;
            }
            $regionDay->status = RegionDayStatus::DistributionPublished;
            $regionDay->save();

            $notified = $first ? $this->openingRecipients($regionDay) : $this->activeAmong(array_keys($changedClients));

            activity('limits')
                ->performedOn($regionDay)
                ->causedBy($by)
                ->event($first ? 'published' : 'updated')
                ->withProperties([
                    'day' => $regionDay->businessDay->dayKey(),
                    'region' => $regionDay->region->code,
                    'version' => $regionDay->distribution_version,
                    'changes_count' => count($changes),
                    'changes' => array_slice($changes, 0, self::AUDIT_CHANGES),
                    'notified_clients' => count($notified),
                ])
                ->log(__($first ? 'limits.audit.published' : 'limits.audit.updated'));

            event(new DistributionPublished($regionDay->id, $regionDay->distribution_version, $first, $notified, $by->id));

            return new DistributionResult(
                $regionDay->distribution_version,
                $first,
                count($changes),
                $notified,
                $this->overallocated($regionDay),
            );
        });
    }

    /**
     * Produits en surallocation (RG-B05) : somme des bornes supérieure au quota.
     * `$drafts` : sur les valeurs affichées (brouillons compris), sinon publiées.
     *
     * @return list<array{product_id: int, sku: string, quota: int, sum_limits: int}>
     */
    public function overallocated(RegionDay $regionDay, bool $drafts = false): array
    {
        $quotas = $this->availableQuotas($regionDay);
        $clientIds = $this->clientsQuery($regionDay)->pluck('id')->all();
        $sums = [];
        ClientLimit::query()
            ->where('region_day_id', $regionDay->id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('product_id', array_keys($quotas))
            ->with('product:id,sku')
            ->get()
            ->each(function (ClientLimit $limit) use (&$sums, $drafts): void {
                $value = $drafts ? $limit->displayed() : $limit->max_qty;
                $sums[$limit->product_id] ??= ['sku' => $limit->product->sku, 'sum' => 0];
                $sums[$limit->product_id]['sum'] += (int) $value;
            });

        $over = [];
        foreach ($sums as $productId => $sum) {
            if ($sum['sum'] > $quotas[$productId]) {
                $over[] = ['product_id' => $productId, 'sku' => $sum['sku'], 'quota' => $quotas[$productId], 'sum_limits' => $sum['sum']];
            }
        }

        return $over;
    }

    /**
     * Clients notifiés à la première publication (RG-B06) : tous les clients
     * actifs de la région ; en politique OBLIGATOIRE (RG-B04), seulement ceux
     * qui ont au moins une borne > 0. `$drafts` : sur les valeurs affichées
     * (brouillons compris), pour le récapitulatif avant l'envoi.
     *
     * @return list<int>
     */
    public function openingRecipients(RegionDay $regionDay, bool $drafts = false): array
    {
        $query = $this->clientsQuery($regionDay)->where('status', ClientStatus::Active->value);
        if ($this->settings->get('limits.no_limit_policy') === 'REQUIRED') {
            $limits = ClientLimit::query()
                ->select('client_id')
                ->where('region_day_id', $regionDay->id)
                ->whereIn('product_id', array_keys($this->availableQuotas($regionDay)));
            if ($drafts) {
                $limits->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->where('has_draft', true)->where('draft_max_qty', '>', 0))
                    ->orWhere(fn (Builder $q) => $q->where('has_draft', false)->where('max_qty', '>', 0)));
            } else {
                $limits->where('max_qty', '>', 0);
            }
            $query->whereIn('id', $limits);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Récapitulatif avant « Valider et envoyer aux clients » (EF-REG-02) :
     * première publication ou modifications, brouillons à envoyer, clients
     * qui seront notifiés (N-03 ou N-04), produits en surallocation (RG-B05).
     * Mêmes règles que publish(), sans rien écrire.
     *
     * @return array{first: bool, drafts: int, notified_clients: int, overallocated: list<array{product_id: int, sku: string, quota: int, sum_limits: int}>}
     */
    public function previewPublication(RegionDay $regionDay): array
    {
        $regionDay = RegionDay::query()->with(['businessDay', 'region'])->findOrFail($regionDay->id);
        $first = ! $regionDay->isDistributionPublished();
        $quotas = $this->availableQuotas($regionDay);
        $clientIds = $this->clientsQuery($regionDay)->pluck('id')->flip();

        $applicable = ClientLimit::query()
            ->where('region_day_id', $regionDay->id)
            ->where('has_draft', true)
            ->get(['client_id', 'product_id'])
            ->filter(fn (ClientLimit $limit) => $clientIds->has($limit->client_id) && isset($quotas[$limit->product_id]));

        $notified = $first
            ? $this->openingRecipients($regionDay, drafts: true)
            : $this->activeAmong($applicable->pluck('client_id')->unique()->values()->all());

        return [
            'first' => $first,
            'drafts' => $applicable->count(),
            'notified_clients' => count($notified),
            'overallocated' => $this->overallocated($regionDay, drafts: true),
        ];
    }

    /**
     * Verrouille la journée régionale et vérifie qu'elle accepte des bornes :
     * région du périmètre de l'utilisateur ce jour-là, journée ouverte,
     * quotas de la région publiés (RG-B06).
     */
    private function lockForWriting(RegionDay $regionDay, User $by, bool $lock = true): RegionDay
    {
        $regionDay = RegionDay::query()
            ->with(['businessDay', 'region'])
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->findOrFail($regionDay->id);
        /** @var BusinessDay $day */
        $day = $regionDay->businessDay;

        if (! $this->perimeter->canAccessRegion($by, $regionDay->region_id, $day->day)) {
            throw new ApiException(ErrorCode::NotFound, __('errors.NOT_FOUND'));
        }
        $this->calendar->assertOpen($day);
        if ($regionDay->status === RegionDayStatus::Closed) {
            throw new ApiException(ErrorCode::DayNotOpen, __('days.region_closed', ['region' => $regionDay->region->code]));
        }
        if (! $regionDay->isQuotaPublished()) {
            throw new ApiException(ErrorCode::DayNotOpen, __('limits.quotas_not_published', [
                'region' => $regionDay->region->code,
                'day' => $day->day->format('d/m/Y'),
            ]));
        }

        return $regionDay;
    }

    /**
     * Clients actifs parmi ceux donnés (N-04 : clients impactés).
     *
     * @param  list<int>  $clientIds
     * @return list<int>
     */
    private function activeAmong(array $clientIds): array
    {
        return Client::query()
            ->whereKey($clientIds)
            ->where('status', ClientStatus::Active->value)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
