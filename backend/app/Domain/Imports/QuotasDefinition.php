<?php

namespace App\Domain\Imports;

use App\Domain\Access\Perimeter;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Quotas\QuotaBook;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Import des quotas d'une journée (§8.1, Annexe B.1, EF-ADV-02) : clé
 * code_produit, une colonne par code région du périmètre de l'auteur (RG-S05),
 * la colonne « designation (information) » ignorée. Les valeurs deviennent
 * des brouillons (QuotaBook::saveDrafts), jamais publiés (RG-Q09) :
 *
 * - Remplacer : pour chaque région du fichier, chaque produit actif prend la
 *   valeur du fichier ; une cellule vide ou un produit absent vaut 0 ;
 * - Compléter : seules les cellules renseignées changent.
 *
 * Valeur actuelle d'une cellule : son brouillon, sinon le quota publié, 0 sans
 * ligne en base. Une valeur sous le compteur validé V(r,p) est refusée
 * (RG-Q05, §5.8).
 */
final class QuotasDefinition extends ImportDefinition
{
    private const KEY = 'code_produit';

    private const DESIGNATION = 'designation';

    /** @var array<string, Region>|null en-tête normalisé → région où l'auteur peut écrire, par code */
    private ?array $regions = null;

    /** @var array<string, string> en-tête normalisé → codes des régions qui s'y confondent */
    private array $ambiguous = [];

    /** @var array<string, Region> code → région, colonnes du fichier dans leur ordre */
    private array $fileRegions = [];

    /** @var array<string, Product> code → produit actif, dans l'ordre de la matrice */
    private array $products = [];

    /** @var array<string, true> produits cités dans le fichier */
    private array $present = [];

    /** @var array<int, array<int, RegionQuota>> région → produit → quota de la journée */
    private array $quotas = [];

    public function __construct(
        User $actor,
        private readonly BusinessDay $day,
        private readonly ImportMode $mode,
        private readonly QuotaBook $book,
        private readonly Perimeter $perimeter,
    ) {
        parent::__construct($actor);
    }

    public function columns(): array
    {
        return [self::KEY, ...array_values(array_map(fn (Region $region) => $region->code, $this->regions()))];
    }

    public function requiredColumns(): array
    {
        return [self::KEY];
    }

    /** Désignation du produit, pour se repérer : ignorée à l'import (Annexe B.1). */
    public function informationColumns(): array
    {
        return [self::DESIGNATION];
    }

    /** Annexe B.1 : code_produit, designation (information), puis une colonne par région. */
    public function templateColumns(): array
    {
        return [
            ['header' => self::KEY, 'required' => true, 'information' => false],
            ['header' => self::informationHeader(self::DESIGNATION), 'required' => false, 'information' => true],
            ...array_values(array_map(fn (Region $region) => ['header' => $region->code, 'required' => false, 'information' => false], $this->regions())),
        ];
    }

    public function sheetTitle(): ?string
    {
        return 'QUOTAS';
    }

    /** Une journée clôturée n'est plus modifiable (§4.4) : DAY_NOT_OPEN à l'application. */
    public function checkContext(AnalysisResult $result): void
    {
        if ($this->day->isOpen()) {
            return;
        }

        $message = __('imports.errors.day_closed', ['day' => $this->day->day->format('d/m/Y')]);
        $result->fileError(null, $message);
        $result->conflict = new ApiException(ErrorCode::DayNotOpen, $message);
    }

    /**
     * En-tête : code_produit, au moins une colonne de région ; une journée
     * régionale clôturée n'accepte plus de quota.
     */
    public function resolveHeader(array $header, AnalysisResult $result): array
    {
        $columns = parent::resolveHeader($header, $result);

        $byCode = [];
        foreach ($this->regions() as $region) {
            $byCode[$region->code] = $region;
        }

        $this->fileRegions = [];
        foreach (array_keys($columns) as $column) {
            if (isset($byCode[$column])) {
                $this->fileRegions[$column] = $byCode[$column];
            }
        }

        if ($this->fileRegions === []) {
            // Une colonne de région refusée est déjà signalée : pas d'erreur en double.
            if (! $result->hasErrors()) {
                $result->fileError(null, __('imports.errors.no_region_column'));
            }

            return $columns;
        }

        RegionDay::query()
            ->with('region:id,code')
            ->where('business_day_id', $this->day->id)
            ->whereIn('region_id', array_map(fn (Region $region) => $region->id, array_values($this->fileRegions)))
            ->where('status', RegionDayStatus::Closed->value)
            ->get()
            ->each(fn (RegionDay $regionDay) => $result->fileError($regionDay->region->code, __('imports.errors.header', [
                'column' => $regionDay->region->code,
                'message' => __('imports.errors.region_day_closed'),
            ])));

        return $columns;
    }

    /** Colonne code_produit, ou code d'une région du périmètre (casse ignorée). */
    protected function headerColumn(string $name): ?string
    {
        if ($name === self::KEY) {
            return self::KEY;
        }

        return ($this->regions()[$name] ?? null)?->code;
    }

    /**
     * Toute autre colonne doit être le code d'une région active du périmètre :
     * sinon, erreur de fichier (§8.1, « Colonne EST2 : code région inconnu »).
     * Les colonnes d'information et la colonne « erreurs » d'un fichier
     * d'erreurs corrigé sont ignorées.
     */
    protected function unknownHeader(string $name, AnalysisResult $result): void
    {
        if (in_array($name, $this->ignoredHeaders(), true)) {
            return;
        }
        $this->regions();
        if (isset($this->ambiguous[$name])) {
            $result->fileError(mb_strtoupper($name), __('imports.errors.header', [
                'column' => mb_strtoupper($name),
                'message' => __('imports.errors.ambiguous_region_column', ['codes' => $this->ambiguous[$name]]),
            ]));

            return;
        }

        $region = Region::query()->get(['id', 'code', 'is_active'])
            ->first(fn (Region $region) => ImportSheet::normalizeHeader($region->code) === $name);

        $column = $region?->code ?? mb_strtoupper($name);
        $message = match (true) {
            $region === null => __('imports.errors.unknown_region_column'),
            ! $region->is_active => __('imports.errors.inactive_region_column'),
            default => __('imports.errors.region_column_out_of_perimeter'),
        };

        $result->fileError($column, __('imports.errors.header', ['column' => $column, 'message' => $message]));
    }

    public function keyColumn(): string
    {
        return self::KEY;
    }

    public function key(RowContext $row): ?string
    {
        return $this->code($row, self::KEY);
    }

    public function label(string $key): string
    {
        return $key;
    }

    public function describe(string $key): string
    {
        return __('imports.subjects.product', ['key' => $key]);
    }

    /**
     * Produits actifs (tous : en mode Remplacer, un produit absent vaut 0) et
     * quotas actuels de la journée pour les régions du fichier.
     */
    public function preload(array $keys): void
    {
        $this->present = array_fill_keys($keys, true);

        $this->products = [];
        foreach ($this->activeProducts()->get() as $product) {
            $this->products[mb_strtoupper($product->sku)] = $product;
        }

        $this->quotas = [];
        $regionIds = array_map(fn (Region $region) => $region->id, array_values($this->fileRegions));
        RegionQuota::query()
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->where('region_days.business_day_id', $this->day->id)
            ->whereIn('region_days.region_id', $regionIds)
            ->select('region_quotas.*', 'region_days.region_id')
            ->get()
            ->each(function (RegionQuota $quota): void {
                $this->quotas[(int) $quota->getAttribute('region_id')][$quota->product_id] = $quota;
            });
    }

    public function check(RowContext $row, string $key): ?RowPlan
    {
        $product = $this->products[$key] ?? null;
        if ($product === null) {
            $row->fail(self::KEY, __('imports.errors.unknown_or_inactive_product', ['value' => $key]));
        }

        $values = $this->values($row);
        if ($product === null) {
            return null;
        }

        $changes = [];
        $cells = [];
        foreach ($this->fileRegions as $code => $region) {
            if ($row->failed($code)) {
                continue;
            }

            $new = $values[$code] ?? ($this->mode === ImportMode::Replace ? 0 : null);
            if ($new === null) {
                continue;
            }

            $quota = $this->quotas[$region->id][$product->id] ?? null;
            if ($quota !== null && $new < $quota->validated_qty) {
                $row->fail($code, __('imports.errors.below_validated', ['value' => $new, 'validated' => $quota->validated_qty]));

                continue;
            }

            $old = self::current($quota);
            if ($new !== $old) {
                $changes[$code] = ['old' => $old, 'new' => $new];
                $cells[] = ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $new];
            }
        }

        if ($row->hasErrors()) {
            return null;
        }

        return new RowPlan($row->line, $key, $key, $changes === [] ? RowPlan::UNCHANGED : RowPlan::UPDATE, $changes, ['cells' => $cells], $product);
    }

    /** Code produit manquant ou invalide : les valeurs sont contrôlées quand même. */
    public function checkWithoutKey(RowContext $row): void
    {
        $this->values($row);
    }

    /**
     * Mode Remplacer : un produit actif absent du fichier vaut 0 dans chaque
     * région du fichier. Il apparaît dans l'aperçu sans numéro de ligne ; une
     * remise à 0 sous le compteur validé est une erreur de fichier.
     */
    public function finish(AnalysisResult $result): void
    {
        if ($this->mode !== ImportMode::Replace || $result->summary === null) {
            return;
        }

        foreach ($this->products as $sku => $product) {
            if (isset($this->present[$sku])) {
                continue;
            }

            $changes = [];
            $cells = [];
            foreach ($this->fileRegions as $code => $region) {
                $quota = $this->quotas[$region->id][$product->id] ?? null;
                if ($quota === null) {
                    continue;
                }

                if ($quota->validated_qty > 0) {
                    $result->fileError($code, __('imports.errors.header', [
                        'column' => $code,
                        'message' => __('imports.errors.absent_below_validated', ['product' => $sku, 'validated' => $quota->validated_qty]),
                    ]));

                    continue;
                }

                $old = self::current($quota);
                if ($old !== 0) {
                    $changes[$code] = ['old' => $old, 'new' => 0];
                    $cells[] = ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => 0];
                }
            }

            if ($changes !== []) {
                $result->plans[] = new RowPlan(null, $sku, $sku, RowPlan::UPDATE, $changes, ['cells' => $cells], $product);
                $result->summary[RowPlan::UPDATE]++;
            }
        }
    }

    public function apply(RowPlan $plan): void
    {
        $this->applyAll([$plan]);
    }

    /**
     * Toutes les cellules en un seul appel à QuotaBook::saveDrafts : un seul
     * événement QuotaDraftsSaved, journée et cellules verrouillées ensemble.
     */
    public function applyAll(array $plans): void
    {
        $cells = [];
        foreach ($plans as $plan) {
            array_push($cells, ...$plan->attributes['cells']);
        }

        $this->book->saveDrafts($this->day, $cells, $this->actor);
    }

    public function auditProperties(): array
    {
        return ['day' => $this->day->dayKey(), 'mode' => $this->mode->value];
    }

    /**
     * Une ligne par produit actif, dans l'ordre de la matrice : valeur
     * actuelle de chaque région (brouillon, sinon quota publié), vide sans
     * ligne en base.
     */
    public function templateRows(): iterable
    {
        $regions = array_values($this->regions());

        $values = [];
        RegionQuota::query()
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->where('region_days.business_day_id', $this->day->id)
            ->whereIn('region_days.region_id', array_map(fn (Region $region) => $region->id, $regions))
            ->get(['region_days.region_id', 'region_quotas.product_id', 'region_quotas.quantity', 'region_quotas.draft_quantity'])
            ->each(function ($row) use (&$values): void {
                $values[(int) $row->region_id][(int) $row->product_id] = (int) ($row->draft_quantity ?? $row->quantity);
            });

        foreach ($this->activeProducts()->lazy(500) as $product) {
            yield [
                $product->sku,
                $product->name,
                ...array_map(fn (Region $region) => $values[$region->id][$product->id] ?? null, $regions),
            ];
        }
    }

    /**
     * Quantités lues dans les colonnes de régions : entier de 0 à 1 000 000,
     * null pour une cellule vide ou en erreur.
     *
     * @return array<string, ?int> code région → quantité
     */
    private function values(RowContext $row): array
    {
        $values = [];
        foreach (array_keys($this->fileRegions) as $code) {
            $values[$code] = $this->integer($row, $code, 0, QuotaBook::MAX_QUANTITY, 'imports.errors.positive_integer');
        }

        return $values;
    }

    /** Valeur actuelle : brouillon, sinon quota publié ; 0 sans ligne (RG-Q01). */
    private static function current(?RegionQuota $quota): int
    {
        return $quota === null ? 0 : ($quota->draft_quantity ?? $quota->quantity);
    }

    /**
     * Régions où l'auteur peut écrire des quotas : actives (RG-Q02) et dans
     * son périmètre à la date de la journée (comme la matrice et QuotaBook),
     * par code.
     *
     * @return array<string, Region> en-tête normalisé → région
     */
    private function regions(): array
    {
        if ($this->regions !== null) {
            return $this->regions;
        }

        $allowed = $this->perimeter->regionIds($this->actor, $this->day->day);

        $this->regions = [];
        Region::query()
            ->where('is_active', true)
            ->when($allowed !== null, fn (Builder $query) => $query->whereKey($allowed))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->each(function (Region $region): void {
                $this->regions[ImportSheet::normalizeHeader($region->code)][] = $region;
            });
        // Deux codes que seuls « - » et « _ » distinguent (SUD-EST, SUD_EST) désignent la
        // même colonne : aucune des deux n'est lue, plutôt que d'écrire dans la mauvaise région.
        $this->ambiguous = array_map(
            // Codes triés en PHP : l'ordre de la base dépend de sa collation.
            fn (array $regions) => collect($regions)->map(fn (Region $region) => $region->code)->sort()->implode(', '),
            array_filter($this->regions, fn (array $regions) => count($regions) > 1),
        );
        $this->regions = array_map(fn (array $regions) => $regions[0], array_diff_key($this->regions, $this->ambiguous));

        return $this->regions;
    }

    /**
     * Produits actifs dans l'ordre de la matrice (EF-ADV-02) : famille (ordre,
     * code), puis ordre et code du produit.
     *
     * @return Builder<Product>
     */
    private function activeProducts(): Builder
    {
        return Product::query()
            ->select('products.*')
            ->join('product_families', 'product_families.id', '=', 'products.family_id')
            // RG-Q02, RG-Q07 : actifs le jour de la journée (désactivation programmée comprise).
            ->activeOn($this->day->day)
            ->orderBy('product_families.sort_order')
            ->orderBy('product_families.code')
            ->orderBy('products.sort_order')
            ->orderBy('products.sku');
    }

    /**
     * En-têtes ignorés : « designation », « designation (information) » et la
     * colonne « erreurs » du fichier d'erreurs annoté.
     *
     * @return list<string>
     */
    private function ignoredHeaders(): array
    {
        $headers = [ImportSheet::normalizeHeader(__('imports.report.column'))];
        foreach ($this->informationColumns() as $column) {
            $headers[] = ImportSheet::normalizeHeader($column);
            $headers[] = ImportSheet::normalizeHeader(self::informationHeader($column));
        }

        return $headers;
    }
}
