<?php

use App\Domain\Referentials\ClientRegistry;
use App\Domain\Referentials\PriceBook;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\Feature\Imports\Workbook;

// Gabarits (contrat §6) : en-têtes de l'annexe B, pré-remplis avec les
// données actuelles ; réimportés tels quels, ils ne changent rien.

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));
    $this->admin = admin();
    $this->actingAs($this->admin);

    // Réimporte le gabarit téléchargé tel quel.
    $this->reimport = function (TestResponse $template, string $type): array {
        $file = UploadedFile::fake()->createWithContent('gabarit.xlsx', $template->streamedContent());
        $id = $this->post('/api/v1/imports', ['type' => $type, 'file' => $file])->assertStatus(202)->json('id');

        return $this->getJson("/api/v1/imports/{$id}")->json();
    };
});

it('prefills the clients template with the clients, their target region and status', function () {
    $centre = Region::factory()->create(['code' => 'CENTRE']);
    $est = Region::factory()->create(['code' => 'EST']);
    $category = ClientCategory::factory()->create(['code' => 'GROSSISTE', 'name' => 'Grossiste']);

    $moving = Client::factory()->create([
        'code' => 'C0002', 'name' => '=HYPERLINK("http://exemple.dz","Cliquez")', 'trade_name' => null,
        'category_id' => $category->id, 'region_id' => $centre->id, 'address' => '12 rue des Martyrs', 'wilaya' => 'Alger',
        'commune' => 'Bab Ezzouar', 'phone' => '0550123456', 'email' => 'a@b.dz', 'nif' => '000123', 'nis' => null,
        'rc' => 'RC1', 'ai' => 'AI1', 'lat' => '36.7212345', 'lng' => '3.1812345',
    ]);
    app(ClientRegistry::class)->scheduleRegionChange($moving, $est->id);
    $moving->save();
    Client::factory()->blocked()->create(['code' => 'C0001', 'name' => 'Client bloqué', 'region_id' => $est->id]);

    $response = $this->get('/api/v1/imports/templates/clients')->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('gabarit-clients-20261008.xlsx');

    $bytes = $response->streamedContent();
    $rows = Workbook::values($bytes);
    expect($rows[1])->toBe([
        'code_client', 'raison_sociale', 'enseigne', 'categorie', 'code_region', 'adresse', 'wilaya', 'commune',
        'telephone', 'email', 'nif', 'nis', 'rc', 'article_imposition', 'latitude', 'longitude', 'statut',
    ])
        ->and($rows[2][0])->toBe('C0001')
        ->and($rows[2][4])->toBe('EST')
        ->and($rows[2][16])->toBe('BLOQUE')
        ->and($rows[3])->toBe([
            'C0002', '=HYPERLINK("http://exemple.dz","Cliquez")', null, 'GROSSISTE', 'EST', '12 rue des Martyrs', 'Alger',
            'Bab Ezzouar', '0550123456', 'a@b.dz', '000123', null, 'RC1', 'AI1', 36.7212345, 3.1812345, 'ACTIF',
        ])
        ->and(count($rows))->toBe(3);

    // Texte commençant par « = » : neutralisé, jamais une formule (§8.4).
    $spreadsheet = Workbook::read($bytes);
    $cell = $spreadsheet->getSheet(0)->getCell('B3');
    expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($cell->getStyle()->getQuotePrefix())->toBeTrue();
    $spreadsheet->disconnectWorksheets();

    // Réimporté tel quel : rien ne change, le changement de région en attente est conservé.
    $import = ($this->reimport)($response, 'CLIENTS');
    expect($import['status'])->toBe('READY')
        ->and($import['summary'])->toBe(['create' => 0, 'update' => 0, 'unchanged' => 2]);
});

it('prefills the products template', function () {
    $family = ProductFamily::factory()->create(['code' => 'JUS']);
    Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'family_id' => $family->id,
        'format' => '1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'units_per_sales_unit' => 6,
        'sales_units_per_pallet' => 80, 'ean' => '6130000000420', 'vat_rate' => '19.00', 'is_active' => false, 'sort_order' => 10,
    ]);

    $response = $this->get('/api/v1/imports/templates/products')->assertOk();
    $rows = Workbook::values($response->streamedContent());

    expect($rows)->toBe([
        1 => ['code_produit', 'designation', 'designation_courte', 'code_famille', 'format', 'libelle_uv', 'unites_par_uv', 'uv_par_palette', 'ean', 'taux_tva', 'statut', 'ordre_affichage'],
        2 => ['P0042', 'Jus orange 1 L', 'Orange 1 L', 'JUS', '1 L', 'Fardeau 6 × 1 L', 6, 80, '6130000000420', 19.0, 'INACTIF', 10],
    ]);

    $import = ($this->reimport)($response, 'PRODUCTS');
    expect($import['status'])->toBe('READY')
        ->and($import['summary'])->toBe(['create' => 0, 'update' => 0, 'unchanged' => 1]);
});

it('prefills the prices template with the current and future prices', function () {
    $product = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L']);
    $book = app(PriceBook::class);
    $book->setPrice($product, '800', BusinessClock::parseDay('2026-09-01'), null);
    $book->setPrice($product, '900', BusinessClock::parseDay('2026-10-01'), null);
    $book->setPrice($product, '950.5', BusinessClock::parseDay('2026-11-01'), null);

    $response = $this->get('/api/v1/imports/templates/prices')->assertOk();
    $bytes = $response->streamedContent();
    $rows = Workbook::values($bytes);

    expect($rows[1])->toBe(['code_produit', 'prix_ht', 'date_effet', 'designation (information)'])
        ->and(count($rows))->toBe(3)
        ->and([$rows[2][0], $rows[2][1], $rows[2][3]])->toBe(['P0042', 900.0, 'Jus orange 1 L'])
        ->and($rows[3][1])->toBe(950.5);

    // Date d'effet : cellule date Excel au format JJ/MM/AAAA.
    $spreadsheet = Workbook::read($bytes);
    $cell = $spreadsheet->getSheet(0)->getCell('C2');
    expect(ExcelDate::excelToDateTimeObject($cell->getValue())->format('Y-m-d'))->toBe('2026-10-01')
        ->and($cell->getStyle()->getNumberFormat()->getFormatCode())->toBe('dd/mm/yyyy')
        ->and($cell->getFormattedValue())->toBe('01/10/2026');
    $spreadsheet->disconnectWorksheets();

    $import = ($this->reimport)($response, 'PRICES');
    expect($import['status'])->toBe('READY')
        ->and($import['summary'])->toBe(['create' => 0, 'update' => 0, 'unchanged' => 2]);
});

it('offers an empty template when there is no data yet', function (string $type, string $first) {
    $rows = Workbook::values($this->get("/api/v1/imports/templates/{$type}")->assertOk()->streamedContent());

    expect(count($rows))->toBe(1)->and($rows[1][0])->toBe($first);
})->with([['clients', 'code_client'], ['products', 'code_produit'], ['prices', 'code_produit']]);

it('answers 404 for an unknown template', function () {
    expectProblem($this->get('/api/v1/imports/templates/quotas'), 404, 'NOT_FOUND');
});
