<?php

use App\Domain\Imports\ImportStatus;
use App\Domain\Imports\ImportType;
use App\Jobs\AnalyzeImport;
use App\Models\Import;
use App\Models\Region;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Imports\Workbook;

// Dépôt d'un fichier (§8.1 étapes 1-2, §8.4, §12 « Fichiers ») : .xlsx sans
// macros, 5 Mo au plus, stocké hors de la partie publique, analysé en file
// d'attente.

beforeEach(function () {
    Storage::fake('local');
    $this->admin = admin();
    Region::factory()->create(['code' => 'CENTRE']);

    $this->rows = [
        ['code_client', 'raison_sociale', 'code_region'],
        ['C0001', 'Client A', 'CENTRE'],
    ];

    $this->send = fn (mixed $file, string $type = 'CLIENTS') => $this->actingAs($this->admin)
        ->post('/api/v1/imports', ['type' => $type, 'file' => $file]);

    $this->expectRejected = function ($response, string $message) {
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors.file', [$message]);
        expect(Import::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    };
});

it('stores the file and queues its analysis', function () {
    Queue::fake();

    $response = ($this->send)(Workbook::upload($this->rows, 'clients.xlsx'));

    $response->assertStatus(202)
        ->assertJsonPath('status', 'PENDING')
        ->assertJsonPath('rows_total', 0)
        ->assertJsonPath('summary', null)
        ->assertJsonPath('errors', [])
        ->assertJsonPath('preview', [])
        ->assertJsonPath('analyzed_at', null)
        ->assertJsonMissingPath('file_path');

    $import = Import::query()->sole();
    expect($import->type)->toBe(ImportType::Clients)
        ->and($import->status)->toBe(ImportStatus::Pending)
        ->and($import->user_id)->toBe($this->admin->id)
        ->and($import->file_path)->toMatch('#^imports/[0-9a-f-]{36}\.xlsx$#');
    Storage::disk('local')->assertExists($import->file_path);

    Queue::assertPushed(AnalyzeImport::class, fn (AnalyzeImport $job) => $job->import->is($import));
    expect(new AnalyzeImport($import))->toBeInstanceOf(ShouldQueue::class);
});

it('keeps only the base name of the uploaded file', function () {
    Queue::fake();

    ($this->send)(Workbook::upload($this->rows, "..\\dossier/clients\x07 octobre.xlsx"))->assertStatus(202)
        ->assertJsonPath('original_name', 'clients octobre.xlsx');
});

it('accepts an .xlsx file whatever the case of its extension', function () {
    Queue::fake();

    ($this->send)(Workbook::upload($this->rows, 'CLIENTS.XLSX'))->assertStatus(202);
});

it('rejects an .xls workbook', function () {
    ($this->expectRejected)(
        ($this->send)(UploadedFile::fake()->createWithContent('clients.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 512))),
        'Fichier .xlsx attendu, 5 Mo maximum.',
    );
});

it('rejects an .xlsm workbook', function () {
    $bytes = Workbook::withExtraPart(Workbook::bytes($this->rows), 'xl/vbaProject.bin', 'VBA');

    ($this->expectRejected)(
        ($this->send)(UploadedFile::fake()->createWithContent('clients.xlsm', $bytes)),
        'Fichier .xlsx attendu, 5 Mo maximum.',
    );
});

it('rejects a workbook containing macros, even renamed .xlsx', function () {
    $bytes = Workbook::withExtraPart(Workbook::bytes($this->rows), 'xl/vbaProject.bin', 'VBA');

    ($this->expectRejected)(
        ($this->send)(UploadedFile::fake()->createWithContent('clients.xlsx', $bytes)),
        'Les classeurs contenant des macros sont refusés : enregistrez le fichier au format « Classeur Excel (.xlsx) ».',
    );
});

it('rejects a file that is not a spreadsheet', function (string $content) {
    ($this->expectRejected)(
        ($this->send)(UploadedFile::fake()->createWithContent('clients.xlsx', $content)),
        'Fichier .xlsx attendu, 5 Mo maximum.',
    );
})->with([
    'text' => "code_client;raison_sociale\nC0001;Client A\n",
    'empty' => '',
    'other zip' => fn () => Workbook::withExtraPart(
        Workbook::withExtraPart(Workbook::bytes([['a']]), 'readme.txt', 'x'),
        '[Content_Types].xml',
        '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    ),
]);

it('rejects a file over 5 MB', function () {
    ($this->expectRejected)(
        ($this->send)(UploadedFile::fake()->create('clients.xlsx', 5 * 1024 + 1)),
        'Fichier .xlsx attendu, 5 Mo maximum.',
    );
});

it('requires a known type and a file', function () {
    $response = $this->actingAs($this->admin)->post('/api/v1/imports', ['type' => 'QUOTAS']);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonValidationErrors(['type', 'file']);
    expect(Import::query()->count())->toBe(0);
});
