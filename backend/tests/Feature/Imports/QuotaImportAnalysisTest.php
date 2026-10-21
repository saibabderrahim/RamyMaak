<?php

use App\Domain\Access\Role;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Imports\ImportProcessor;
use App\Jobs\AnalyzeImport;
use App\Models\Import;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\Imports\QuotaImportFixtures;
use Tests\Feature\Imports\Workbook;

// Analyse d'un fichier de quotas (§8.1, Annexe B.1, contrat §5) : en-tête
// (code_produit, codes région du périmètre), clé produit, valeurs entières,
// cohérence avec le compteur validé, rapport annoté.

beforeEach(function () {
    QuotaImportFixtures::install($this);

    $this->messages = fn (int $id) => collect(($this->report)($id)['errors'])->pluck('message')->all();
});

describe('header', function () {
    it('accepts mixed-case headers and ignores the designation column', function () {
        $id = ($this->upload)([
            ['  Code Produit ', 'Désignation (information)', 'centre', 'Est '],
            ['p0042', '=1/0', 480, null],
        ], 'COMPLETE')->assertStatus(202)->json('id');

        expect(($this->report)($id))
            ->status->toBe('READY')
            ->errors->toBe([])
            ->preview->toBe([['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 450, 'new' => 480]]]]);
    });

    it('refuses a column whose code only differs from another region by « - » or « _ »', function () {
        // Codes créés avant le contrôle de la création des régions.
        Region::factory()->create(['code' => 'SUD-EST']);
        Region::factory()->create(['code' => 'SUD_EST']);

        $id = ($this->upload)([['code_produit', 'SUD_EST', 'CENTRE'], ['P0042', 77, 480]], 'COMPLETE')->assertStatus(202)->json('id');

        expect(($this->report)($id)['status'])->toBe('INVALID')
            ->and(($this->messages)($id))->toBe(['Colonne SUD_EST : code région ambigu (SUD-EST, SUD_EST)']);
        expect(RegionDay::query()->whereHas('region', fn ($q) => $q->whereIn('code', ['SUD-EST', 'SUD_EST']))
            ->whereHas('quotas', fn ($q) => $q->whereNotNull('draft_quantity'))->exists())->toBeFalse();
    });

    it('refuses an unknown, inactive or duplicated region column', function () {
        $id = ($this->upload)([
            ['code_produit', 'designation', 'CENTRE', 'EST2', 'SUD', 'Centre', 'Remarque'],
            ['P0042', 'Jus orange 1 L', 480, 10, 20, 480, 'à revoir'],
        ])->json('id');

        expect(($this->report)($id))
            ->status->toBe('INVALID')
            ->rows_total->toBe(0)
            ->summary->toBeNull()
            ->errors->toBe([
                ['row' => null, 'column' => 'EST2', 'message' => 'Colonne EST2 : code région inconnu'],
                ['row' => null, 'column' => 'SUD', 'message' => 'Colonne SUD : région inactive'],
                ['row' => null, 'column' => 'REMARQUE', 'message' => 'Colonne REMARQUE : code région inconnu'],
                ['row' => null, 'column' => 'CENTRE', 'message' => 'Colonne CENTRE : colonne présente plusieurs fois'],
            ]);
        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
    });

    it('requires the product code column and at least one region column', function () {
        $id = ($this->upload)([['designation (information)', 'CENTRE'], ['Jus orange 1 L', 480]])->json('id');
        expect(($this->messages)($id))->toBe(['Colonne code_produit : colonne obligatoire absente']);

        $id = ($this->upload)([['code_produit', 'designation (information)'], ['P0042', 'Jus orange 1 L']])->json('id');
        expect(($this->messages)($id))->toBe(['Aucune colonne de région : ajoutez une colonne par code région (ex. CENTRE), comme dans le gabarit.']);
    });

    it('accepts a corrected error report, its « erreurs » column ignored', function () {
        $id = ($this->upload)([
            ['code_produit', 'designation (information)', 'CENTRE', 'erreurs'],
            ['P0042', 'Jus orange 1 L', 480, 'Ligne 2, colonne CENTRE : 12,5 n\'est pas un entier positif'],
        ])->json('id');

        expect(($this->report)($id))->status->toBe('READY');
    });

    it('refuses a region column outside the perimeter of a restricted ADV (RG-S05)', function () {
        $restricted = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);

        $id = ($this->upload)([['code_produit', 'CENTRE', 'EST'], ['P0042', 480, 250]], 'COMPLETE', $restricted)->assertStatus(202)->json('id');
        expect(($this->report)($id)['errors'])->toBe([
            ['row' => null, 'column' => 'CENTRE', 'message' => 'Colonne CENTRE : région hors de votre périmètre'],
        ]);

        $id = ($this->upload)([['code_produit', 'EST'], ['P0042', 250]], 'COMPLETE', $restricted)->json('id');
        expect(($this->report)($id))
            ->status->toBe('READY')
            ->preview->toBe([['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['EST' => ['old' => 200, 'new' => 250]]]]);
    });

    it('refuses the column of a region whose day is closed', function () {
        RegionDay::query()->whereKey(($this->regionDay)($this->est)->id)->update(['status' => RegionDayStatus::Closed->value]);

        $id = ($this->upload)([['code_produit', 'CENTRE', 'EST'], ['P0042', 480, 250]], 'COMPLETE')->json('id');

        expect(($this->report)($id)['errors'])->toBe([
            ['row' => null, 'column' => 'EST', 'message' => 'Colonne EST : la journée de cette région est clôturée'],
        ]);
    });

    it('accepts a region created since the opening of the day', function () {
        Region::factory()->create(['code' => 'NORD']);

        $id = ($this->upload)([['code_produit', 'NORD'], ['P0042', 75]], 'COMPLETE')->json('id');

        expect(($this->report)($id))
            ->status->toBe('READY')
            ->preview->toBe([['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['NORD' => ['old' => 0, 'new' => 75]]]]);
    });
});

describe('rows', function () {
    it('reports unknown or inactive products, duplicates and invalid values', function () {
        $id = ($this->upload)([
            QuotaImportFixtures::HEADER,
            ['P0042', 'Jus orange 1 L', 480, 200, null],
            ['P0999', 'Jus retiré', 10, null, null],
            ['P9999', 'Inconnu', null, null, 5],
            ['P0057', 'Jus pomme 1 L', 12.5, -3, 'beaucoup'],
            ['P0103', 'Lben 1 L', '12,5', 1000001, true],
            ['p0042', 'Jus orange 1 L', 490, null, null],
            [null, 'Sans code', 'abc', null, null],
            ['P 01', 'Code invalide', 5, null, null],
        ], 'COMPLETE')->json('id');

        $report = ($this->report)($id);
        expect($report['status'])->toBe('INVALID')
            ->and($report['rows_total'])->toBe(8)
            ->and($report['rows_ok'])->toBe(1)
            ->and($report['rows_error'])->toBe(7)
            ->and($report['has_error_report'])->toBeTrue()
            ->and(collect($report['errors'])->pluck('message')->all())->toBe([
                'Ligne 3, colonne code_produit : code produit P0999 inconnu ou inactif',
                'Ligne 4, colonne code_produit : code produit P9999 inconnu ou inactif',
                'Ligne 5, colonne CENTRE : 12,5 n\'est pas un entier positif',
                'Ligne 5, colonne EST : -3 n\'est pas un entier positif',
                'Ligne 5, colonne OUEST : beaucoup n\'est pas un entier positif',
                'Ligne 6, colonne CENTRE : 12,5 n\'est pas un entier positif',
                'Ligne 6, colonne EST : 1000001 dépasse le maximum autorisé (1000000)',
                'Ligne 6, colonne OUEST : VRAI n\'est pas un entier positif',
                'Ligne 7, colonne code_produit : produit P0042 déjà présent ligne 2',
                'Ligne 8, colonne code_produit : valeur obligatoire',
                'Ligne 8, colonne CENTRE : abc n\'est pas un entier positif',
                'Ligne 9, colonne code_produit : P 01 n\'est pas un code valide (lettres, chiffres, tirets et tirets bas, 30 caractères au plus)',
            ])
            ->and($report['errors'][2])->toBe(['row' => 5, 'column' => 'CENTRE', 'message' => 'Ligne 5, colonne CENTRE : 12,5 n\'est pas un entier positif']);
    });

    it('accepts integers typed as text, as formulas or as whole decimals', function () {
        $id = ($this->upload)([
            ['code_produit', 'CENTRE', 'EST', 'OUEST'],
            ['P0042', ' 1 200 ', '=100+150', 30.0],
        ], 'COMPLETE')->json('id');

        expect(($this->report)($id))
            ->status->toBe('READY')
            ->preview->toBe([['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => [
                'CENTRE' => ['old' => 450, 'new' => 1200],
                'EST' => ['old' => 200, 'new' => 250],
                'OUEST' => ['old' => 0, 'new' => 30],
            ]]]);
    });

    it('refuses a value below the validated counter (RG-Q05)', function (string $mode) {
        ($this->validate)($this->centre, $this->pomme, 300);
        ($this->validate)($this->est, $this->orange, 120);

        $id = ($this->upload)([
            ['code_produit', 'CENTRE', 'EST'],
            ['P0057', 250, null],
            ['P0042', 450, 120],
        ], $mode)->json('id');

        expect(($this->messages)($id))->toBe(['Ligne 2, colonne CENTRE : 250 inférieur aux 300 déjà validés']);
    })->with(['REPLACE', 'COMPLETE']);

    it('refuses an empty cell worth 0 below the validated counter in replace mode', function () {
        ($this->validate)($this->est, $this->orange, 120);

        $id = ($this->upload)([['code_produit', 'EST'], ['P0042', null], ['P0057', 10]])->json('id');

        expect(($this->messages)($id))->toBe(['Ligne 2, colonne EST : 0 inférieur aux 120 déjà validés']);
    });

    it('refuses a product absent from a replace file when its validated counter is not 0', function () {
        ($this->validate)($this->centre, $this->pomme, 100);

        $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', 450]])->json('id');
        expect(($this->report)($id)['errors'])->toBe([[
            'row' => null,
            'column' => 'CENTRE',
            'message' => 'Colonne CENTRE : produit P0057 absent du fichier (0 en mode Remplacer) : inférieur aux 100 déjà validés',
        ]]);

        // En mode Compléter, un produit absent ne change pas.
        $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', 450]], 'COMPLETE')->json('id');
        expect(($this->report)($id))->status->toBe('READY');
    });
});

it('annotates the error report with the messages of each row and of the header', function () {
    $id = ($this->upload)([
        ['code_produit', 'designation (information)', 'CENTRE', 'EST', 'OUEST'],
        ['P0042', 'Jus orange 1 L', 480, null, null],
        ['P0999', 'Jus retiré', 10, null, null],
        ['P0057', 'Jus pomme 1 L', 12.5, null, null],
    ], 'COMPLETE')->json('id');

    $response = $this->get("/api/v1/imports/{$id}/error-report")->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('quotas-erreurs.xlsx');

    $bytes = $response->streamedContent();
    $rows = Workbook::values($bytes);
    expect($rows[1][5])->toBe('erreurs')
        ->and($rows[2][5])->toBeNull()
        ->and($rows[3][5])->toBe('Ligne 3, colonne code_produit : code produit P0999 inconnu ou inactif')
        ->and($rows[4][5])->toBe('Ligne 4, colonne CENTRE : 12,5 n\'est pas un entier positif');

    // Cellule fautive surlignée.
    $spreadsheet = Workbook::read($bytes);
    $sheet = $spreadsheet->getSheet(0);
    expect($sheet->getStyle('C4')->getFill()->getStartColor()->getARGB())->toBe('FFFFC7CE')
        ->and($sheet->getStyle('C2')->getFill()->getFillType())->toBe('none');
    $spreadsheet->disconnectWorksheets();
});

it('analyses in the background with the perimeter of the author', function () {
    Queue::fake();
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);

    $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', 480]], 'COMPLETE', $restricted)->json('id');
    (new AnalyzeImport(Import::query()->findOrFail($id)))->handle(app(ImportProcessor::class));

    expect(($this->report)($id)['errors'])->toBe([
        ['row' => null, 'column' => 'CENTRE', 'message' => 'Colonne CENTRE : région hors de votre périmètre'],
    ]);
});

it('reports a day closed before the analysis', function () {
    Queue::fake();
    $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', 480]])->assertStatus(202)->json('id');
    $this->calendar->close($this->day);

    (new AnalyzeImport(Import::query()->findOrFail($id)))->handle(app(ImportProcessor::class));

    expect(($this->report)($id))
        ->status->toBe('INVALID')
        ->has_error_report->toBeFalse()
        ->errors->toBe([['row' => null, 'column' => null, 'message' => 'La journée du 08/10/2026 est clôturée : ses quotas ne sont plus modifiables.']]);
});

it('previews at most 200 changed products with complete counters', function () {
    $family = $this->orange->family_id;
    $rows = [['code_produit', 'OUEST']];
    for ($i = 1; $i <= 201; $i++) {
        $sku = sprintf('Q%04d', $i);
        Product::factory()->create(['sku' => $sku, 'family_id' => $family]);
        $rows[] = [$sku, $i];
    }

    $id = ($this->upload)($rows, 'COMPLETE')->json('id');

    $report = ($this->report)($id);
    expect($report['status'])->toBe('READY')
        ->and($report['summary'])->toBe(['create' => 0, 'update' => 201, 'unchanged' => 0])
        ->and($report['preview'])->toHaveCount(200)
        ->and($report['preview_truncated'])->toBeTrue()
        ->and($report['preview'][199])->toBe(['row' => 201, 'key' => 'Q0200', 'action' => 'update', 'changes' => ['OUEST' => ['old' => 0, 'new' => 200]]]);
});

it('reads only the first sheet, numeric cells formatted as text included', function () {
    $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', null]], 'COMPLETE')->json('id');
    expect(($this->report)($id))->summary->toBe(['create' => 0, 'update' => 0, 'unchanged' => 1]);

    $id = $this->post('/api/v1/imports', [
        'type' => 'QUOTAS', 'business_day' => QuotaImportFixtures::DAY, 'mode' => 'COMPLETE',
        'file' => Workbook::upload([['code_produit', 'CENTRE'], ['P0042', null]], 'quotas.xlsx', function (Worksheet $sheet) {
            $sheet->getCell('B2')->setValueExplicit('480', 's');
            $other = $sheet->getParent()->createSheet();
            $other->fromArray([['code_produit', 'EST2'], ['P0042', 'x']]);
        }),
    ])->json('id');

    expect(($this->report)($id))
        ->status->toBe('READY')
        ->preview->toBe([['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 450, 'new' => 480]]]]);
});
