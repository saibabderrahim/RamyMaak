<?php

namespace App\Domain\Quotas;

use App\Domain\Access\Perimeter;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotas région (§5.2) : saisie en brouillon (RG-Q03), copie depuis une
 * autre journée, publication par région, sélection ou toutes les régions
 * (RG-Q04 à RG-Q08). Le compteur validé V(r,p) n'est jamais modifié ici
 * (§5.8) ; une diminution sous ce compteur est refusée à la publication.
 */
final class QuotaBook
{
    /** Quota maximal d'une cellule (entier, RG-Q01). */
    public const MAX_QUANTITY = 1_000_000;

    public function __construct(
        private readonly DayCalendar $calendar,
        private readonly Perimeter $perimeter,
    ) {}

    /**
     * Enregistre des cellules en brouillon. `quantity` null annule le
     * brouillon de la cellule ; une valeur égale au quota publié aussi.
     *
     * @param  list<array{region_id: int, product_id: int, quantity: ?int}>  $cells
     * @return int nombre de cellules dont le brouillon a changé
     *
     * @throws ApiException DAY_NOT_OPEN
     * @throws ValidationException cellule invalide (région, produit, valeur)
     */
    public function saveDrafts(BusinessDay $day, array $cells, User $by): int
    {
        if ($cells === []) {
            return 0;
        }

        return DB::transaction(function () use ($day, $cells, $by): int {
            $day = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);
            $this->calendar->assertOpen($day);

            $regions = $this->usableRegions($by, array_column($cells, 'region_id'));
            $products = Product::query()
                ->whereKey(array_unique(array_column($cells, 'product_id')))
                ->where('is_active', true)
                ->pluck('id')
                ->flip();

            $errors = [];
            foreach ($cells as $index => $cell) {
                $region = $regions->get($cell['region_id']);
                if ($region === null) {
                    $errors["cells.{$index}.region_id"] = [$this->regionError($by, $cell['region_id'])];
                }
                if (! $products->has($cell['product_id'])) {
                    $errors["cells.{$index}.product_id"] = [__('quotas.product_unknown')];
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            /** @var array<int, RegionDay> $regionDays région → journée régionale */
            $regionDays = [];
            foreach (array_unique(array_column($cells, 'region_id')) as $regionId) {
                $regionDay = $this->calendar->regionDayFor($day, $regions->get($regionId));
                if ($regionDay->status === RegionDayStatus::Closed) {
                    throw new ApiException(ErrorCode::DayNotOpen, __('days.region_closed', ['region' => $regions->get($regionId)->code]));
                }
                $regionDays[$regionId] = $regionDay;
            }

            $existing = RegionQuota::query()
                ->whereIn('region_day_id', array_map(fn (RegionDay $rd) => $rd->id, $regionDays))
                ->whereIn('product_id', array_unique(array_column($cells, 'product_id')))
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (RegionQuota $q) => $q->region_day_id.'|'.$q->product_id);

            $changed = 0;
            $changedRegions = [];
            foreach ($cells as $cell) {
                $regionDay = $regionDays[$cell['region_id']];
                $key = $regionDay->id.'|'.$cell['product_id'];
                $quota = $existing->get($key) ?? new RegionQuota([
                    'region_day_id' => $regionDay->id,
                    'product_id' => $cell['product_id'],
                    'quantity' => 0,
                ]);

                $draft = $cell['quantity'];
                if ($draft !== null && $draft === $quota->quantity) {
                    $draft = null;
                }
                if ($quota->exists && $quota->draft_quantity === $draft) {
                    continue;
                }
                if (! $quota->exists && $draft === null) {
                    continue;
                }

                $quota->draft_quantity = $draft;
                $quota->updated_by = $by->id;
                $quota->save();
                $existing->put($key, $quota);
                $changed++;
                $changedRegions[$cell['region_id']] = true;
            }

            if ($changed > 0) {
                event(new QuotaDraftsSaved($day->id, array_keys($changedRegions), $by->id));
            }

            return $changed;
        });
    }

    /**
     * Copie en brouillon les quotas publiés d'une autre journée
     * (« Copier depuis la journée… », EF-ADV-02). Remplacer : chaque cellule
     * prend la valeur source (0 si absente) ; Compléter : seules les cellules
     * vides (ni quota publié, ni brouillon) sont renseignées.
     *
     * @param  list<int>|null  $regionIds  null : toutes les régions du périmètre
     */
    public function copyFrom(BusinessDay $target, BusinessDay $source, ?array $regionIds, bool $replace, User $by): int
    {
        $regions = $this->usableRegions($by, $regionIds);
        if ($regionIds !== null) {
            $this->assertRegions($by, $regionIds, $regions);
        }
        $productIds = Product::query()->where('is_active', true)->pluck('id')->all();

        $sourceValues = $this->publishedValues($source, $regions->keys()->all());
        $targetValues = $replace ? [] : $this->currentValues($target, $regions->keys()->all());

        $cells = [];
        foreach ($regions as $region) {
            foreach ($productIds as $productId) {
                $value = $sourceValues[$region->id][$productId] ?? 0;
                if (! $replace && (($targetValues[$region->id][$productId] ?? 0) > 0 || $value === 0)) {
                    continue;
                }
                $cells[] = ['region_id' => $region->id, 'product_id' => $productId, 'quantity' => $value];
            }
        }

        return $this->saveDrafts($target, $cells, $by);
    }

    /**
     * Publie les brouillons (RG-Q03, RG-Q04) des régions demandées, ou de
     * toutes les régions du périmètre. Tout ou rien : une diminution sous la
     * quantité déjà validée (RG-Q05) refuse l'ensemble (QUOTA_BELOW_VALIDATED).
     * Une première publication exige au moins un quota > 0 (§4.2, étape 2).
     *
     * @param  list<int>|null  $regionIds  null : toutes les régions
     */
    public function publish(BusinessDay $day, ?array $regionIds, User $by): PublicationResult
    {
        return DB::transaction(function () use ($day, $regionIds, $by): PublicationResult {
            $day = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);
            $this->calendar->assertOpen($day);

            $regions = $this->usableRegions($by, $regionIds);
            $explicit = $regionIds !== null;
            if ($explicit) {
                $this->assertRegions($by, $regionIds, $regions);
                foreach ($regions as $region) {
                    $this->calendar->regionDayFor($day, $region);
                }
            }

            $regionDays = RegionDay::query()
                ->with('region')
                ->where('business_day_id', $day->id)
                ->whereIn('region_id', $regions->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $published = [];
            $skipped = [];
            $empty = [];
            $violations = [];

            foreach ($regionDays as $regionDay) {
                $code = $regionDay->region->code;
                if ($regionDay->status === RegionDayStatus::Closed) {
                    $skipped[] = ['region_id' => $regionDay->region_id, 'code' => $code, 'reason' => PublicationResult::CLOSED];

                    continue;
                }

                $quotas = RegionQuota::query()
                    ->with('product:id,sku,is_active')
                    ->where('region_day_id', $regionDay->id)
                    ->orderBy('product_id')
                    ->lockForUpdate()
                    ->get();

                // RG-Q02 : un brouillon sur un produit désactivé depuis n'est pas publié.
                $drafts = $quotas->filter(fn (RegionQuota $q) => $q->hasDraft() && $q->product->is_active);
                $first = ! $regionDay->isQuotaPublished();

                if ($first && ! $quotas->contains(fn (RegionQuota $q) => ($q->product->is_active ? ($q->draft_quantity ?? $q->quantity) : 0) > 0)) {
                    if ($explicit) {
                        $empty[] = $code;
                    } else {
                        $skipped[] = ['region_id' => $regionDay->region_id, 'code' => $code, 'reason' => PublicationResult::EMPTY];
                    }

                    continue;
                }
                if (! $first && $drafts->isEmpty()) {
                    $skipped[] = ['region_id' => $regionDay->region_id, 'code' => $code, 'reason' => PublicationResult::NO_CHANGES];

                    continue;
                }

                $changes = [];
                foreach ($drafts as $quota) {
                    // §5.8 : jamais sous la quantité déjà validée, même en cas d'accès simultané.
                    $updated = RegionQuota::query()
                        ->whereKey($quota->id)
                        ->where('validated_qty', '<=', $quota->draft_quantity)
                        ->update(['quantity' => $quota->draft_quantity, 'draft_quantity' => null, 'updated_by' => $by->id, 'updated_at' => now()]);

                    if ($updated === 0) {
                        $violations[] = [
                            'region_id' => $regionDay->region_id,
                            'region' => $code,
                            'product_id' => $quota->product_id,
                            'sku' => $quota->product->sku,
                            'validated' => $quota->validated_qty,
                            'requested' => $quota->draft_quantity,
                        ];

                        continue;
                    }
                    $changes[] = ['product_id' => $quota->product_id, 'sku' => $quota->product->sku, 'old' => $quota->quantity, 'new' => $quota->draft_quantity];
                }
                RegionQuota::query()
                    ->where('region_day_id', $regionDay->id)
                    ->whereNotNull('draft_quantity')
                    ->whereIn('product_id', $quotas->reject(fn (RegionQuota $q) => $q->product->is_active)->pluck('product_id'))
                    ->update(['draft_quantity' => null]);

                $regionDay->quota_version++;
                if ($first) {
                    $regionDay->quota_published_at = now();
                    $regionDay->quota_published_by = $by->id;
                }
                if ($regionDay->status === RegionDayStatus::QuotaDraft) {
                    $regionDay->status = RegionDayStatus::QuotaPublished;
                }
                $regionDay->quota_last_published_at = now();
                $regionDay->save();

                activity('quotas')
                    ->performedOn($regionDay)
                    ->causedBy($by)
                    ->event($first ? 'published' : 'updated')
                    ->withProperties([
                        'day' => $day->dayKey(),
                        'region' => $code,
                        'version' => $regionDay->quota_version,
                        'changes' => $changes,
                    ])
                    ->log(__($first ? 'quotas.audit.published' : 'quotas.audit.updated'));

                event(new QuotasPublished($regionDay->id, $regionDay->quota_version, $first, count($changes), $by->id));

                $published[] = [
                    'region_id' => $regionDay->region_id,
                    'code' => $code,
                    'version' => $regionDay->quota_version,
                    'first' => $first,
                    'changes' => count($changes),
                ];
            }

            if ($empty !== []) {
                throw ValidationException::withMessages(['region_ids' => array_map(
                    fn (string $code) => __('quotas.empty_first_publication', ['region' => $code]),
                    $empty,
                )]);
            }
            if ($violations !== []) {
                throw new ApiException(ErrorCode::QuotaBelowValidated, __('quotas.below_validated'), ['violations' => $violations]);
            }

            return new PublicationResult($published, $skipped);
        });
    }

    /**
     * Régions actives du périmètre de l'utilisateur (RG-S05), restreintes aux
     * identifiants demandés.
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, Region>
     */
    private function usableRegions(User $by, ?array $ids): Collection
    {
        $allowed = $this->perimeter->regionIds($by);

        return Region::query()
            ->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereKey(array_values(array_unique($ids))))
            ->when($allowed !== null, fn ($q) => $q->whereKey($allowed))
            ->orderBy('code')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, Region>  $usable
     */
    private function assertRegions(User $by, array $ids, Collection $usable): void
    {
        $missing = array_values(array_diff(array_unique($ids), $usable->keys()->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages(['region_ids' => [$this->regionError($by, $missing[0])]]);
        }
    }

    private function regionError(User $by, int $regionId): string
    {
        $exists = Region::query()->whereKey($regionId)->where('is_active', true)->exists();
        $allowed = $this->perimeter->regionIds($by);

        return $exists && $allowed !== null && ! in_array($regionId, $allowed, true)
            ? __('quotas.region_out_of_perimeter')
            : __('quotas.region_unknown');
    }

    /**
     * Quotas publiés d'une journée : région → produit → quantité.
     *
     * @param  list<int>  $regionIds
     * @return array<int, array<int, int>>
     */
    private function publishedValues(BusinessDay $day, array $regionIds): array
    {
        $values = [];
        RegionQuota::query()
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->where('region_days.business_day_id', $day->id)
            ->where('region_days.quota_version', '>', 0)
            ->whereIn('region_days.region_id', $regionIds)
            ->get(['region_days.region_id', 'region_quotas.product_id', 'region_quotas.quantity'])
            ->each(function ($row) use (&$values): void {
                $values[(int) $row->region_id][(int) $row->product_id] = (int) $row->quantity;
            });

        return $values;
    }

    /**
     * Valeur courante (brouillon, sinon publiée) : région → produit → quantité.
     *
     * @param  list<int>  $regionIds
     * @return array<int, array<int, int>>
     */
    private function currentValues(BusinessDay $day, array $regionIds): array
    {
        $values = [];
        RegionQuota::query()
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->where('region_days.business_day_id', $day->id)
            ->whereIn('region_days.region_id', $regionIds)
            ->get(['region_days.region_id', 'region_quotas.product_id', 'region_quotas.quantity', 'region_quotas.draft_quantity'])
            ->each(function ($row) use (&$values): void {
                $values[(int) $row->region_id][(int) $row->product_id] = (int) ($row->draft_quantity ?? $row->quantity);
            });

        return $values;
    }
}
