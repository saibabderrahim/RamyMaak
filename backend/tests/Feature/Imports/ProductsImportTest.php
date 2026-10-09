<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductFamily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\Imports\Workbook;

// Import du catalogue (Annexe B.4, EF-PRD-04) : clé code_produit, création ou
// mise à jour directe des fiches.

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));

    $this->admin = admin();
    $this->jus = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus']);
    $this->lait = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait']);
    $this->existing = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'family_id' => $this->jus->id,
        'format' => '1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'units_per_sales_unit' => 6,
        'sales_units_per_pallet' => 80, 'ean' => '6130000000420', 'vat_rate' => '19.00', 'is_active' => true, 'sort_order' => 10,
    ]);

    $this->header = [
        'code_produit', 'designation', 'designation_courte', 'code_famille', 'format', 'libelle_uv',
        'unites_par_uv', 'uv_par_palette', 'ean', 'taux_tva', 'statut', 'ordre_affichage',
    ];

    $this->upload = fn (array $rows, ?Closure $tweak = null) => $this->actingAs($this->admin)
        ->post('/api/v1/imports', ['type' => 'PRODUCTS', 'file' => Workbook::upload($rows, 'produits.xlsx', $tweak)]);
});

it('creates and updates products, empty cells left unchanged', function () {
    $rows = [
        $this->header,
        ['p0100', 'Lait entier 1 L', null, 'lait', '1 L', 'Pack 6 × 1 L', '=2*3', 120, 6130000001007, 9, 'ACTIF', 20],
        ['P0042', 'Jus orange 1 L nouvelle recette', null, null, null, null, null, null, null, 0.09, 'INACTIF', null],
    ];
    // Taux saisi en pourcentage : « 9 % » vaut 9.
    $id = ($this->upload)($rows, fn (Worksheet $sheet) => $sheet->getStyle('J3')->getNumberFormat()
        ->setFormatCode(NumberFormat::FORMAT_PERCENTAGE))->assertStatus(202)->json('id');

    $this->getJson("/api/v1/imports/{$id}")
        ->assertJsonPath('status', 'READY')
        ->assertJsonPath('summary', ['create' => 1, 'update' => 1, 'unchanged' => 0])
        ->assertJsonPath('preview.0.key', 'P0100')
        ->assertJsonPath('preview.0.action', 'create')
        ->assertJsonPath('preview.0.changes.family', ['old' => null, 'new' => 'LAIT'])
        ->assertJsonPath('preview.0.changes.units_per_sales_unit', ['old' => null, 'new' => 6])
        ->assertJsonPath('preview.0.changes.short_name', ['old' => null, 'new' => 'Lait entier 1 L'])
        ->assertJsonPath('preview.1', [
            'row' => 3,
            'key' => 'P0042',
            'action' => 'update',
            'changes' => [
                'name' => ['old' => 'Jus orange 1 L', 'new' => 'Jus orange 1 L nouvelle recette'],
                'vat_rate' => ['old' => 19, 'new' => 9],
                'is_active' => ['old' => true, 'new' => false],
            ],
        ]);

    $this->postJson("/api/v1/imports/{$id}/apply")->assertOk()->assertJsonPath('status', 'APPLIED');

    $created = Product::query()->where('sku', 'P0100')->sole();
    expect($created->only(['name', 'short_name', 'family_id', 'format', 'sales_unit_label', 'units_per_sales_unit', 'sales_units_per_pallet', 'ean', 'vat_rate', 'sort_order']))
        ->toBe([
            'name' => 'Lait entier 1 L',
            'short_name' => 'Lait entier 1 L',
            'family_id' => $this->lait->id,
            'format' => '1 L',
            'sales_unit_label' => 'Pack 6 × 1 L',
            'units_per_sales_unit' => 6,
            'sales_units_per_pallet' => 120,
            'ean' => '6130000001007',
            'vat_rate' => '9.00',
            'sort_order' => 20,
        ])
        ->and($created->fresh()->is_active)->toBeTrue();

    $updated = $this->existing->fresh();
    expect($updated->name)->toBe('Jus orange 1 L nouvelle recette')
        ->and($updated->vat_rate)->toBe('9.00')
        ->and($updated->is_active)->toBeFalse()
        ->and($updated->short_name)->toBe('Orange 1 L')
        ->and($updated->family_id)->toBe($this->jus->id)
        ->and($updated->ean)->toBe('6130000000420')
        ->and($updated->sort_order)->toBe(10);

    // Changements des fiches regroupés avec l'entrée de l'import.
    $entry = AuditLog::query()->where('log_name', 'imports')->sole();
    expect($entry->properties['summary'])->toBe(['create' => 1, 'update' => 1, 'unchanged' => 0])
        ->and(AuditLog::query()->where('batch_uuid', $entry->batch_uuid)->where('subject_type', 'product')->pluck('subject_id')->sort()->values()->all())
        ->toBe([$this->existing->id, $created->id]);
});

it('reports invalid product rows', function () {
    $rows = [
        $this->header,
        ['P0101', 'Jus pomme 1 L', null, 'JUS', null, null, 6, null, null, 19, null, null],
        ['P0102', 'Jus mangue 1 L', null, 'SODA', null, 'Fardeau', 2.5, 0, '12AB', 120, 'EPUISE', -1],
        ['P0042', null, str_repeat('x', 61), null, null, null, null, null, null, null, null, null],
    ];
    $id = ($this->upload)($rows)->json('id');

    $messages = collect($this->getJson("/api/v1/imports/{$id}")
        ->assertJsonPath('status', 'INVALID')
        ->assertJsonPath('rows_error', 3)
        ->json('errors'))->pluck('message')->all();

    expect($messages)->toBe([
        'Ligne 2, colonne libelle_uv : valeur obligatoire pour créer le produit P0101',
        'Ligne 3, colonne unites_par_uv : 2,5 n\'est pas un entier positif',
        'Ligne 3, colonne uv_par_palette : 0 n\'est pas un entier positif',
        'Ligne 3, colonne ordre_affichage : -1 n\'est pas un entier positif ou nul',
        'Ligne 3, colonne code_famille : code famille inconnu : SODA',
        'Ligne 3, colonne ean : 12AB n\'est pas un code EAN valide (8 à 14 chiffres)',
        'Ligne 3, colonne taux_tva : 120 n\'est pas un taux de TVA valide (de 0 à 100, 2 décimales au plus)',
        'Ligne 3, colonne statut : statut inconnu : EPUISE (ACTIF ou INACTIF)',
        'Ligne 4, colonne designation_courte : 60 caractères au plus',
    ]);

    expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
    expect(Product::query()->count())->toBe(1);
});

it('keeps the leading zeros of a code typed as text', function () {
    $rows = [$this->header, [null, 'Lben 1 L', null, 'LAIT', null, 'Pack', 6, null, null, 19, null, null]];
    $id = ($this->upload)($rows, fn (Worksheet $sheet) => $sheet->getCell('A2')->setValueExplicit('00123', DataType::TYPE_STRING))->json('id');

    $this->getJson("/api/v1/imports/{$id}")->assertJsonPath('status', 'READY')->assertJsonPath('preview.0.key', '00123');
});
