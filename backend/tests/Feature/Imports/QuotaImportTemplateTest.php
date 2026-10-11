<?php

use App\Domain\Access\Role;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\Feature\Imports\QuotaImportFixtures;
use Tests\Feature\Imports\Workbook;

// Gabarit des quotas (§8.1, Annexe B.1, contrat §5) : une ligne par produit
// actif dans l'ordre de la matrice, une colonne par région active du
// périmètre, pré-remplies avec la valeur courante (brouillon, sinon publié).

beforeEach(function () {
    QuotaImportFixtures::install($this);

    $this->template = fn (?string $day = QuotaImportFixtures::DAY, $user = null) => $this->actingAs($user ?? $this->adv)
        ->get('/api/v1/imports/templates/quotas'.($day === null ? '' : "?day={$day}"));
});

it('prefills one row per active product and one column per active region with the current values', function () {
    $response = ($this->template)()->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('gabarit-quotas-20261008.xlsx');

    $bytes = $response->streamedContent();
    // Ordre de la matrice : famille (ordre, code), puis ordre et code du produit ;
    // ni le produit inactif P0999, ni la région inactive SUD.
    expect(Workbook::values($bytes))->toBe([
        1 => QuotaImportFixtures::HEADER,
        2 => ['P0042', 'Jus orange 1 L', 450, 200, null],
        3 => ['P0057', 'Jus pomme 1 L', 300, null, null],
        4 => ['P0103', 'Lben 1 L', null, null, null],
    ]);

    $spreadsheet = Workbook::read($bytes);
    $sheet = $spreadsheet->getSheet(0);
    expect($sheet->getTitle())->toBe('QUOTAS')
        ->and($sheet->getCell('C2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getStyle('B1')->getFont()->getItalic())->toBeTrue()
        ->and($sheet->getComment('A1')->getText()->getPlainText())->not->toBe('');
    $spreadsheet->disconnectWorksheets();
});

it('names the template after its day', function () {
    $tomorrow = $this->calendar->open(BusinessClock::parseDay('2026-10-11'), $this->adv);

    $response = ($this->template)($tomorrow->dayKey())->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('gabarit-quotas-20261011.xlsx')
        // Aucun quota sur cette journée : cellules vides.
        ->and(Workbook::values($response->streamedContent())[2])->toBe(['P0042', 'Jus orange 1 L', null, null, null]);
});

it('limits the columns to the regions of a restricted ADV (RG-S05)', function () {
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->sud->id]);

    $rows = Workbook::values(($this->template)(QuotaImportFixtures::DAY, $restricted)->assertOk()->streamedContent());

    expect($rows[1])->toBe(['code_produit', 'designation (information)', 'EST'])
        ->and($rows[2])->toBe(['P0042', 'Jus orange 1 L', 200]);
});

it('adds a region created since the opening of the day', function () {
    Region::factory()->create(['code' => 'NORD']);

    expect(Workbook::values(($this->template)()->assertOk()->streamedContent())[1])
        ->toBe(['code_produit', 'designation (information)', 'CENTRE', 'EST', 'NORD', 'OUEST']);
});

it('changes nothing when reimported as is, in either mode', function (string $mode) {
    $file = UploadedFile::fake()->createWithContent('gabarit.xlsx', ($this->template)()->streamedContent());
    $id = $this->post('/api/v1/imports', ['type' => 'QUOTAS', 'business_day' => QuotaImportFixtures::DAY, 'mode' => $mode, 'file' => $file])
        ->assertStatus(202)
        ->json('id');

    expect(($this->report)($id))
        ->status->toBe('READY')
        ->summary->toBe(['create' => 0, 'update' => 0, 'unchanged' => 3])
        ->preview->toBe([]);
})->with(['REPLACE', 'COMPLETE']);

it('requires an open day', function (?string $day, string $message) {
    $response = ($this->template)($day);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.day', [$message]);
})->with([
    'missing' => [null, 'Le champ journée est obligatoire.'],
    'malformed' => ['08/10/2026', 'Date attendue au format AAAA-MM-JJ.'],
    'impossible date' => ['2026-02-30', 'Date attendue au format AAAA-MM-JJ.'],
    'not opened' => ['2026-10-09', 'La journée du 09/10/2026 n\'est pas ouverte.'],
]);

it('refuses a closed day', function () {
    $this->calendar->close($this->day);

    $response = ($this->template)();
    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.day', ['La journée du 08/10/2026 est clôturée : ses quotas ne sont plus modifiables.']);
    expect(BusinessDay::query()->sole()->isOpen())->toBeFalse();
});

it('requires quota.edit, checked before the day', function (User $user) {
    expectProblem(($this->template)(null, $user), 403, 'FORBIDDEN');
    expectProblem(($this->template)(QuotaImportFixtures::DAY, $user), 403, 'FORBIDDEN');
})->with([
    'regional' => fn () => userWithRole(Role::Regional),
    // L'Admin lit les quotas (quota.view) sans les saisir (contrat §2).
    'admin' => fn () => admin(),
    'client' => fn () => userWithRole(Role::Client),
]);
