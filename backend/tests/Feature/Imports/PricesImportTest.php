<?php

use App\Domain\Referentials\PriceBook;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\Imports\Workbook;

// Import des prix (Annexe B.5, EF-PRD-03) : clé code_produit + date_effet,
// enregistrement par PriceBook::setPrice (RG-P01).

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));

    $this->admin = admin();
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    app(PriceBook::class)->setPrice($this->orange, '850', BusinessClock::parseDay('2026-10-01'), null);

    $this->upload = fn (array $rows, ?Closure $tweak = null) => $this->actingAs($this->admin)
        ->post('/api/v1/imports', ['type' => 'PRICES', 'file' => Workbook::upload($rows, 'prix.xlsx', $tweak)]);

    // Cellule date Excel (numéro de série au format JJ/MM/AAAA).
    $this->excelDate = fn (string $cell, string $day) => function (Worksheet $sheet) use ($cell, $day) {
        $sheet->setCellValue($cell, ExcelDate::stringToExcel($day));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    };
});

it('records prices with Excel dates and JJ/MM/AAAA texts', function () {
    $rows = [
        ['Code produit', 'Prix HT', 'Date effet', 'designation'],
        ['P0042', 900, null, 'Jus orange'],
        ['p0042', '950,50', '01/11/2026', null],
        ['P0057', 1250.5, '15/10/2026', null],
    ];
    $id = ($this->upload)($rows, ($this->excelDate)('C2', '2026-10-01'))->json('id');

    $this->getJson("/api/v1/imports/{$id}")
        ->assertJsonPath('status', 'READY')
        ->assertJsonPath('summary', ['create' => 2, 'update' => 1, 'unchanged' => 0])
        ->assertJsonPath('preview', [
            ['row' => 2, 'key' => 'P0042 au 01/10/2026', 'action' => 'update', 'changes' => ['price_ht' => ['old' => 850, 'new' => 900]]],
            ['row' => 3, 'key' => 'P0042 au 01/11/2026', 'action' => 'create', 'changes' => ['price_ht' => ['old' => null, 'new' => 950.5], 'valid_from' => ['old' => null, 'new' => '2026-11-01']]],
            ['row' => 4, 'key' => 'P0057 au 15/10/2026', 'action' => 'create', 'changes' => ['price_ht' => ['old' => null, 'new' => 1250.5], 'valid_from' => ['old' => null, 'new' => '2026-10-15']]],
        ]);

    $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();

    $history = fn (Product $product) => ProductPrice::query()->where('product_id', $product->id)->orderBy('valid_from')->get()
        ->map(fn (ProductPrice $p) => [$p->price_ht, $p->valid_from->format('Y-m-d'), $p->valid_to?->format('Y-m-d')])->all();

    expect($history($this->orange))->toBe([['900.00', '2026-10-01', '2026-10-31'], ['950.50', '2026-11-01', null]])
        ->and($history($this->pomme))->toBe([['1250.50', '2026-10-15', null]])
        ->and(ProductPrice::query()->where('valid_from', '2026-11-01')->value('created_by'))->toBe($this->admin->id);

    $entry = AuditLog::query()->where('log_name', 'imports')->sole();
    expect(AuditLog::query()->where('batch_uuid', $entry->batch_uuid)->where('subject_type', 'product_price')->count())->toBe(3);
});

it('leaves an identical price unchanged', function () {
    $id = ($this->upload)([['code_produit', 'prix_ht', 'date_effet'], ['P0042', '850', '01/10/2026']])->json('id');

    $this->getJson("/api/v1/imports/{$id}")
        ->assertJsonPath('status', 'READY')
        ->assertJsonPath('summary', ['create' => 0, 'update' => 0, 'unchanged' => 1])
        ->assertJsonPath('preview', []);
});

it('reports invalid price rows', function () {
    $rows = [
        ['code_produit', 'prix_ht', 'date_effet'],
        ['P0999', 100, '01/11/2026'],
        ['P0057', -5, '31/02/2026'],
        ['P0057', '12.345', '2026-11-01'],
        ['P0042', 1000, '01/11/2026'],
        ['P0042', 1100, '1/11/2026'],
        ['P0042', null, null],
    ];
    $id = ($this->upload)($rows)->json('id');

    $messages = collect($this->getJson("/api/v1/imports/{$id}")->assertJsonPath('status', 'INVALID')->json('errors'))
        ->pluck('message')->all();

    expect($messages)->toBe([
        'Ligne 2, colonne code_produit : code produit inconnu : P0999',
        'Ligne 3, colonne date_effet : 31/02/2026 n\'est pas une date valide (JJ/MM/AAAA)',
        'Ligne 3, colonne prix_ht : -5 n\'est pas un montant valide (nombre positif, 2 décimales au plus)',
        'Ligne 4, colonne prix_ht : 12,345 n\'est pas un montant valide (nombre positif, 2 décimales au plus)',
        'Ligne 6, colonne code_produit : prix P0042 au 01/11/2026 déjà présent ligne 5',
        'Ligne 7, colonne date_effet : valeur obligatoire',
        'Ligne 7, colonne prix_ht : valeur obligatoire',
    ]);

    expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
    expect(ProductPrice::query()->count())->toBe(1);
});
