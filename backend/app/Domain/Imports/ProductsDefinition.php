<?php

namespace App\Domain\Imports;

use App\Models\Product;
use App\Models\ProductFamily;

/**
 * Import du catalogue (Annexe B.4, EF-PRD-04), clé code_produit : création
 * ou mise à jour directe des fiches produits (journalisées par le modèle).
 */
final class ProductsDefinition extends ImportDefinition
{
    /** Colonne → [champ de la fiche, longueur maximale]. */
    private const TEXT_COLUMNS = [
        'designation' => ['name', 255],
        'designation_courte' => ['short_name', 60],
        'format' => ['format', 30],
        'libelle_uv' => ['sales_unit_label', 60],
    ];

    /** Colonne → [champ, minimum, maximum] (bornes des colonnes en base). */
    private const INTEGER_COLUMNS = [
        'unites_par_uv' => ['units_per_sales_unit', 1, 65535],
        'uv_par_palette' => ['sales_units_per_pallet', 1, 65535],
        'ordre_affichage' => ['sort_order', 0, 4294967295],
    ];

    /** @var array<string, ProductFamily> code → famille */
    private array $families = [];

    /** @var array<int, ProductFamily> */
    private array $familiesById = [];

    /** @var array<string, Product> code → produit existant */
    private array $products = [];

    public function columns(): array
    {
        return [
            'code_produit', 'designation', 'designation_courte', 'code_famille', 'format', 'libelle_uv',
            'unites_par_uv', 'uv_par_palette', 'ean', 'taux_tva', 'statut', 'ordre_affichage',
        ];
    }

    public function requiredColumns(): array
    {
        return ['code_produit', 'designation', 'code_famille', 'libelle_uv', 'unites_par_uv', 'taux_tva'];
    }

    public function keyColumn(): string
    {
        return 'code_produit';
    }

    public function key(RowContext $row): ?string
    {
        return $this->code($row, 'code_produit');
    }

    public function label(string $key): string
    {
        return $key;
    }

    public function describe(string $key): string
    {
        return __('imports.subjects.product', ['key' => $key]);
    }

    public function preload(array $keys): void
    {
        $this->loadFamilies();

        $this->products = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            foreach (Product::query()->whereIn('sku', $chunk)->get() as $product) {
                $this->products[mb_strtoupper($product->sku)] = $product;
            }
        }
    }

    public function check(RowContext $row, string $key): ?RowPlan
    {
        $product = $this->products[$key] ?? null;
        [$values, $family] = $this->read($row);

        if ($product === null) {
            $this->requireForCreation(
                $row,
                ['designation', 'code_famille', 'libelle_uv', 'unites_par_uv', 'taux_tva'],
                __('imports.errors.required_to_create', ['subject' => $this->describe($key)]),
            );
        }

        if ($row->hasErrors()) {
            return null;
        }

        // Désignation courte par défaut : la désignation, tronquée à 60 caractères.
        if ($product === null && ! isset($values['short_name'])) {
            $values['short_name'] = mb_substr($values['name'], 0, 60);
        }

        $changes = [];
        $attributes = $product === null ? ['sku' => $key] : [];
        foreach ($values as $field => $value) {
            $old = $product?->getAttribute($field);
            if ($product !== null && $old === $value) {
                continue;
            }

            $attributes[$field] = $value;
            $changes[$field === 'family_id' ? 'family' : $field] = match ($field) {
                'family_id' => ['old' => $old === null ? null : ($this->familiesById[$old]->code ?? null), 'new' => $family?->code],
                'vat_rate' => ['old' => $old === null ? null : (float) $old, 'new' => (float) $value],
                default => ['old' => $old, 'new' => $value],
            };
        }

        return RowPlan::createOrUpdate($row->line, $key, $key, $product, $changes, $attributes);
    }

    public function checkWithoutKey(RowContext $row): void
    {
        $this->read($row);
    }

    public function apply(RowPlan $plan): void
    {
        $product = $plan->record instanceof Product ? $plan->record : new Product;
        $product->fill($plan->attributes)->save();
    }

    public function templateRows(): iterable
    {
        $this->loadFamilies();

        foreach (Product::query()->orderBy('sku')->lazy(500) as $product) {
            yield [
                $product->sku,
                $product->name,
                $product->short_name,
                $this->familiesById[$product->family_id]->code ?? null,
                $product->format,
                $product->sales_unit_label,
                $product->units_per_sales_unit,
                $product->sales_units_per_pallet,
                $product->ean,
                (float) $product->vat_rate,
                $product->is_active ? 'ACTIF' : 'INACTIF',
                $product->sort_order,
            ];
        }
    }

    /**
     * Valeurs lues dans les cellules renseignées, indépendamment de la fiche
     * existante : [champ → valeur, famille].
     *
     * @return array{0: array<string, string|int|bool>, 1: ?ProductFamily}
     */
    private function read(RowContext $row): array
    {
        $values = [];
        foreach (self::TEXT_COLUMNS as $column => [$field, $max]) {
            $text = $this->text($row, $column, $max);
            if ($text !== null) {
                $values[$field] = $text;
            }
        }

        foreach (self::INTEGER_COLUMNS as $column => [$field, $min, $max]) {
            $integer = $this->integer($row, $column, $min, $max);
            if ($integer !== null) {
                $values[$field] = $integer;
            }
        }

        $family = $this->family($row);
        if ($family !== null) {
            $values['family_id'] = $family->id;
        }

        $ean = $this->ean($row);
        if ($ean !== null) {
            $values['ean'] = $ean;
        }

        $vatRate = $this->vatRate($row);
        if ($vatRate !== null) {
            $values['vat_rate'] = $vatRate;
        }

        $active = $this->active($row);
        if ($active !== null) {
            $values['is_active'] = $active;
        }

        return [$values, $family];
    }

    private function loadFamilies(): void
    {
        $this->families = [];
        $this->familiesById = [];
        foreach (ProductFamily::query()->get() as $family) {
            $this->families[mb_strtoupper($family->code)] = $family;
            $this->familiesById[$family->id] = $family;
        }
    }

    private function family(RowContext $row): ?ProductFamily
    {
        if (! $row->filled('code_famille')) {
            return null;
        }

        $family = $this->families[mb_strtoupper((string) $row->cell('code_famille')->text)] ?? null;
        if ($family === null) {
            $row->fail('code_famille', __('imports.errors.unknown_family', ['value' => $row->cell('code_famille')->display()]));
        }

        return $family;
    }

    /** Code EAN : 8 à 14 chiffres (une cellule numérique est lue sans exposant). */
    private function ean(RowContext $row): ?string
    {
        if (! $row->filled('ean')) {
            return null;
        }

        $ean = str_replace(' ', '', (string) $row->cell('ean')->text);
        if (preg_match('/^\d{8,14}$/', $ean) !== 1) {
            $row->fail('ean', __('imports.errors.ean', ['value' => $row->cell('ean')->display()]));

            return null;
        }

        return $ean;
    }

    /** Taux de TVA en pourcentage, 0 à 100, deux décimales au plus ; rendu comme le cast decimal:2. */
    private function vatRate(RowContext $row): ?string
    {
        if (! $row->filled('taux_tva')) {
            return null;
        }

        $rate = $this->decimal($row, 'taux_tva');
        if ($rate === null || $rate < 0 || $rate > 100 || abs($rate - round($rate, 2)) > 1e-6) {
            $row->fail('taux_tva', __('imports.errors.vat_rate', ['value' => $row->cell('taux_tva')->display()]));

            return null;
        }

        return number_format($rate, 2, '.', '');
    }

    private function active(RowContext $row): ?bool
    {
        if (! $row->filled('statut')) {
            return null;
        }

        $active = match (mb_strtoupper((string) $row->cell('statut')->text)) {
            'ACTIF', 'ACTIVE' => true,
            'INACTIF', 'INACTIVE' => false,
            default => null,
        };

        if ($active === null) {
            $row->fail('statut', __('imports.errors.product_status', ['value' => $row->cell('statut')->display()]));
        }

        return $active;
    }
}
