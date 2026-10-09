<?php

use App\Domain\Imports\ImportStatus;
use App\Domain\Referentials\ClientStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Import;
use App\Models\Region;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Imports\Workbook;

// Import des clients (Annexe B.3, EF-CLI-02) : création, mise à jour, région
// à J+1 (RG-S04), blocage motivé (EF-CLI-04), rapport d'erreurs, tout ou rien.

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));

    $this->admin = admin(['username' => 'admin.import', 'name' => 'Admin Import']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->category = ClientCategory::factory()->create(['code' => 'DETAILLANT', 'name' => 'Détaillant']);

    $this->existing = Client::factory()->create([
        'code' => 'C0001', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id,
        'phone' => '0550123456', 'email' => null, 'trade_name' => 'El Baraka',
    ]);
    $this->untouched = Client::factory()->create([
        'code' => 'C0002', 'name' => 'Alimentation Générale', 'region_id' => $this->centre->id,
    ]);

    $this->upload = function (array $rows, string $name = 'clients.xlsx') {
        return $this->actingAs($this->admin)
            ->post('/api/v1/imports', ['type' => 'CLIENTS', 'file' => Workbook::upload($rows, $name)]);
    };
});

describe('a valid file', function () {
    beforeEach(function () {
        $this->rows = [
            ['Code Client', 'Raison sociale*', 'enseigne', 'categorie', 'code_region', 'telephone', 'email', 'latitude', 'longitude', 'statut', 'commentaire'],
            // Création : code mis en majuscules, catégorie désignée par son libellé.
            ['c0100', 'Nouvelle Superette', 'Chez Amine', 'détaillant', 'centre', '0661000000', 'contact@amine.dz', 36.7212345, 3.1812345, 'ACTIF', 'ignorée'],
            // Mise à jour : cellules vides inchangées, région à J+1, blocage sans motif.
            ['C0001', null, null, null, 'EST', null, null, null, null, 'BLOQUE', null],
            // Inchangé.
            ['C0002', 'Alimentation Générale', null, null, 'CENTRE', null, null, null, null, 'ACTIF', null],
        ];
    });

    it('is analysed in the background into a report with a preview of the changes', function () {
        $response = ($this->upload)($this->rows);

        $response->assertStatus(202)
            ->assertJsonPath('type', 'CLIENTS')
            ->assertJsonPath('type_label', 'Clients')
            ->assertJsonPath('original_name', 'clients.xlsx')
            ->assertJsonPath('user', ['id' => $this->admin->id, 'username' => 'admin.import', 'name' => 'Admin Import']);

        $this->getJson('/api/v1/imports/'.$response->json('id'))
            ->assertOk()
            ->assertJsonPath('status', 'READY')
            ->assertJsonPath('rows_total', 3)
            ->assertJsonPath('rows_processed', 3)
            ->assertJsonPath('rows_ok', 3)
            ->assertJsonPath('rows_error', 0)
            ->assertJsonPath('summary', ['create' => 1, 'update' => 1, 'unchanged' => 1])
            ->assertJsonPath('errors', [])
            ->assertJsonPath('errors_truncated', false)
            ->assertJsonPath('preview_truncated', false)
            ->assertJsonPath('has_error_report', false)
            ->assertJsonPath('failure_message', null)
            ->assertJsonPath('applied_at', null)
            ->assertJsonPath('preview.0.row', 2)
            ->assertJsonPath('preview.0.key', 'C0100')
            ->assertJsonPath('preview.0.action', 'create')
            ->assertJsonPath('preview.0.changes.name', ['old' => null, 'new' => 'Nouvelle Superette'])
            ->assertJsonPath('preview.0.changes.category', ['old' => null, 'new' => 'DETAILLANT'])
            ->assertJsonPath('preview.0.changes.region', ['old' => null, 'new' => 'CENTRE'])
            ->assertJsonPath('preview.0.changes.lat', ['old' => null, 'new' => 36.7212345])
            ->assertJsonPath('preview.1', [
                'row' => 3,
                'key' => 'C0001',
                'action' => 'update',
                'changes' => [
                    'region' => ['old' => 'CENTRE', 'new' => 'EST'],
                    'status' => ['old' => 'ACTIVE', 'new' => 'BLOCKED'],
                    'status_reason' => ['old' => null, 'new' => 'Import Excel du 08/10/2026'],
                ],
            ])
            ->assertJsonCount(2, 'preview');

        // Rien n'est écrit avant l'application.
        expect(Client::query()->where('code', 'C0100')->exists())->toBeFalse()
            ->and($this->existing->fresh()->status)->toBe(ClientStatus::Active);
    });

    it('stores the original file out of the public disk', function () {
        $id = ($this->upload)($this->rows)->json('id');

        $import = Import::query()->findOrFail($id);
        expect($import->file_path)->toStartWith('imports/')->toEndWith('.xlsx');
        Storage::disk('local')->assertExists($import->file_path);
    });

    it('applies every change through the client registry', function () {
        $id = ($this->upload)($this->rows)->json('id');

        $this->postJson("/api/v1/imports/{$id}/apply")
            ->assertOk()
            ->assertJsonPath('status', 'APPLIED')
            ->assertJsonPath('applied_at', now()->toIso8601String());

        $created = Client::query()->where('code', 'C0100')->firstOrFail();
        expect($created->name)->toBe('Nouvelle Superette')
            ->and($created->trade_name)->toBe('Chez Amine')
            ->and($created->category_id)->toBe($this->category->id)
            ->and($created->region_id)->toBe($this->centre->id)
            ->and($created->phone)->toBe('0661000000')
            ->and($created->email)->toBe('contact@amine.dz')
            ->and($created->lat)->toBe('36.7212345')
            ->and($created->status)->toBe(ClientStatus::Active);

        // Région à la journée suivante (RG-S04), cellules vides inchangées.
        $updated = $this->existing->fresh();
        expect($updated->region_id)->toBe($this->centre->id)
            ->and($updated->next_region_id)->toBe($this->est->id)
            ->and($updated->region_change_on->format('Y-m-d'))->toBe('2026-10-09')
            ->and($updated->name)->toBe('Superette El Baraka')
            ->and($updated->trade_name)->toBe('El Baraka')
            ->and($updated->phone)->toBe('0550123456')
            ->and($updated->status)->toBe(ClientStatus::Blocked)
            ->and($updated->status_reason)->toBe('Import Excel du 08/10/2026')
            ->and($updated->status_changed_by)->toBe($this->admin->id);

        $this->getJson("/api/v1/clients/{$updated->id}")
            ->assertJsonPath('region.code', 'CENTRE')
            ->assertJsonPath('pending_region_change.region.code', 'EST')
            ->assertJsonPath('pending_region_change.effective_on', '2026-10-09');

        expect($this->untouched->fresh()->updated_at->equalTo($this->untouched->updated_at))->toBeTrue();
    });

    it('journals the application with the changes of each record in one batch', function () {
        $id = ($this->upload)($this->rows)->json('id');
        $before = (int) AuditLog::query()->max('id');
        $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();

        $entry = AuditLog::query()->where('log_name', 'imports')->where('event', 'applied')->sole();
        expect($entry->subject_type)->toBe('import')
            ->and($entry->subject_id)->toBe($id)
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->description)->toBe('Import Excel appliqué')
            ->and($entry->properties['type'])->toBe('CLIENTS')
            ->and($entry->properties['file'])->toBe('clients.xlsx')
            ->and($entry->properties['summary'])->toBe(['create' => 1, 'update' => 1, 'unchanged' => 1])
            ->and($entry->batch_uuid)->not->toBeNull();

        $batch = AuditLog::query()->where('batch_uuid', $entry->batch_uuid)->where('log_name', 'referentials')->get();
        expect($batch->pluck('subject_id')->unique()->sort()->values()->all())
            ->toBe(Client::query()->whereIn('code', ['C0001', 'C0100'])->orderBy('id')->pluck('id')->all())
            ->and($batch->where('event', 'created')->count())->toBe(1)
            ->and($batch->every(fn (AuditLog $log) => $log->causer_id === $this->admin->id))->toBeTrue();
        // Aucune entrée de l'application hors du lot.
        expect(AuditLog::query()->where('id', '>', $before)->where(fn ($q) => $q
            ->whereNull('batch_uuid')->orWhere('batch_uuid', '!=', $entry->batch_uuid))->count())->toBe(0);
    });

    it('cannot be applied twice', function () {
        $id = ($this->upload)($this->rows)->json('id');
        $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();

        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'INVALID_TRANSITION');
        expect(Client::query()->where('code', 'C0100')->count())->toBe(1)
            ->and(AuditLog::query()->where('log_name', 'imports')->count())->toBe(1);
    });

    it('is revalidated at application: a new error applies nothing', function () {
        $rows = [
            ['code_client', 'raison_sociale', 'code_region'],
            ['C0300', 'Client du Centre', 'CENTRE'],
            ['C0301', 'Client de l\'Est', 'EST'],
            ['C0001', null, 'EST'],
        ];
        $id = ($this->upload)($rows)->assertStatus(202)->json('id');
        $this->getJson("/api/v1/imports/{$id}")->assertJsonPath('status', 'READY');

        // La région est désactivée entre l'analyse et l'application.
        $this->est->forceFill(['is_active' => false])->save();

        $response = $this->postJson("/api/v1/imports/{$id}/apply");
        expectProblem($response, 409, 'IMPORT_HAS_ERRORS');

        expect(Client::query()->whereIn('code', ['C0300', 'C0301'])->count())->toBe(0)
            ->and($this->existing->fresh()->next_region_id)->toBeNull()
            ->and(AuditLog::query()->where('log_name', 'imports')->count())->toBe(0);

        $this->getJson("/api/v1/imports/{$id}")
            ->assertJsonPath('status', 'INVALID')
            ->assertJsonPath('rows_ok', 1)
            ->assertJsonPath('rows_error', 2)
            ->assertJsonPath('has_error_report', true)
            ->assertJsonPath('errors.0', ['row' => 3, 'column' => 'code_region', 'message' => 'Ligne 3, colonne code_region : la région EST est inactive'])
            ->assertJsonPath('errors.1.row', 4);

        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
    });
});

it('writes nothing when a technical error interrupts the application', function () {
    $rows = [
        ['code_client', 'raison_sociale', 'code_region'],
        ['C0300', 'Client A', 'CENTRE'],
        ['C0301', 'Client B', 'CENTRE'],
    ];
    $id = ($this->upload)($rows)->json('id');

    Client::creating(function (Client $client) {
        if ($client->code === 'C0301') {
            throw new RuntimeException('Connexion perdue');
        }
    });

    expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 500, 'SERVER_ERROR');
    expect(Client::query()->whereIn('code', ['C0300', 'C0301'])->count())->toBe(0)
        ->and(AuditLog::query()->where('log_name', 'imports')->count())->toBe(0)
        ->and(Import::query()->find($id)->status)->toBe(ImportStatus::Ready);
});

describe('an invalid file', function () {
    it('reports every faulty cell in the §8.1 format and cannot be applied', function () {
        $rows = [
            ['code_client', 'raison_sociale', 'code_region', 'email', 'statut'],
            ['C0200', 'Client A', 'EST2', null, null],
            ['C0201', 'Client B', 'CENTRE', 'pas-un-email', null],
            ['c0201', 'Client B bis', 'CENTRE', null, null],
            ['C0202', null, 'CENTRE', null, 'SUSPENDU'],
            ['C0203', 'Client C', 'CENTRE', null, null],
        ];
        $id = ($this->upload)($rows)->json('id');

        $this->getJson("/api/v1/imports/{$id}")
            ->assertJsonPath('status', 'INVALID')
            ->assertJsonPath('rows_total', 5)
            ->assertJsonPath('rows_ok', 1)
            ->assertJsonPath('rows_error', 4)
            ->assertJsonPath('summary', ['create' => 1, 'update' => 0, 'unchanged' => 0])
            ->assertJsonPath('has_error_report', true)
            ->assertJsonPath('errors', [
                ['row' => 2, 'column' => 'code_region', 'message' => 'Ligne 2, colonne code_region : code région inconnu : EST2'],
                ['row' => 3, 'column' => 'email', 'message' => 'Ligne 3, colonne email : pas-un-email n\'est pas une adresse e-mail valide'],
                ['row' => 4, 'column' => 'code_client', 'message' => 'Ligne 4, colonne code_client : client C0201 déjà présent ligne 3'],
                ['row' => 5, 'column' => 'statut', 'message' => 'Ligne 5, colonne statut : statut inconnu : SUSPENDU (ACTIF, BLOQUE ou INACTIF)'],
                ['row' => 5, 'column' => 'raison_sociale', 'message' => 'Ligne 5, colonne raison_sociale : valeur obligatoire pour créer le client C0202'],
            ]);

        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
        expect(Client::query()->count())->toBe(2)
            ->and(Import::query()->find($id)->status)->toBe(ImportStatus::Invalid);
    });

    it('offers the original file annotated with an errors column and highlighted cells', function () {
        $rows = [
            ['code_client', 'raison_sociale', 'code_region', 'email'],
            ['C0200', 'Client A', 'EST2', 'a@b.dz'],
            ['C0201', 'Client B', 'CENTRE', 'pas-un-email'],
            ['C0202', 'Client C', 'CENTRE', null],
        ];
        $id = ($this->upload)($rows, 'Clients octobre.xlsx')->json('id');

        $response = $this->get("/api/v1/imports/{$id}/error-report")->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->and($response->headers->get('Content-Disposition'))->toContain('Clients octobre-erreurs.xlsx');

        $spreadsheet = Workbook::read($response->streamedContent());
        $sheet = $spreadsheet->getSheet(0);

        // Données d'origine conservées, colonne « erreurs » ajoutée.
        expect($sheet->getCell('A2')->getValue())->toBe('C0200')
            ->and($sheet->getCell('E1')->getValue())->toBe('erreurs')
            ->and($sheet->getCell('E2')->getValue())->toBe('Ligne 2, colonne code_region : code région inconnu : EST2')
            ->and($sheet->getCell('E3')->getValue())->toBe('Ligne 3, colonne email : pas-un-email n\'est pas une adresse e-mail valide')
            ->and($sheet->getCell('E4')->getValue())->toBeNull()
            // Cellules fautives surlignées, les autres non.
            ->and($sheet->getStyle('C2')->getFill()->getStartColor()->getARGB())->toBe('FFFFC7CE')
            ->and($sheet->getStyle('D3')->getFill()->getStartColor()->getARGB())->toBe('FFFFC7CE')
            ->and($sheet->getStyle('C3')->getFill()->getFillType())->toBe('none');
        $spreadsheet->disconnectWorksheets();
    });

    it('rejects a file whose header lacks a required column', function () {
        $rows = [
            ['code_client', 'raison sociale', 'region'],
            ['C0200', 'Client A', 'CENTRE'],
        ];
        $id = ($this->upload)($rows)->json('id');

        $this->getJson("/api/v1/imports/{$id}")
            ->assertJsonPath('status', 'INVALID')
            ->assertJsonPath('summary', null)
            ->assertJsonPath('errors', [
                ['row' => null, 'column' => 'code_region', 'message' => 'Colonne code_region : colonne obligatoire absente'],
            ])
            ->assertJsonPath('has_error_report', true);

        $sheet = Workbook::values($this->get("/api/v1/imports/{$id}/error-report")->streamedContent());
        expect($sheet[1][3])->toBe("erreurs\nColonne code_region : colonne obligatoire absente");
    });

    it('checks values: codes, lengths, coordinates, categories and inactive regions', function () {
        $inactive = Region::factory()->inactive()->create(['code' => 'SUD']);
        $rows = [
            ['code_client', 'raison_sociale', 'code_region', 'categorie', 'latitude', 'longitude', 'telephone'],
            ['C 01', 'Client A', 'CENTRE', null, null, null, null],
            ['C0210', 'Client B', 'SUD', 'GROSSISTE', null, null, null],
            ['C0211', 'Client C', 'CENTRE', null, 95, 3.1, null],
            ['C0212', 'Client D', 'CENTRE', null, 36.7, null, str_repeat('0', 31)],
            ['C0213', '=1/0', 'CENTRE', null, null, null, null],
        ];
        $id = ($this->upload)($rows)->json('id');

        $messages = collect($this->getJson("/api/v1/imports/{$id}")->assertJsonPath('status', 'INVALID')->json('errors'))
            ->pluck('message')->all();

        expect($messages)->toBe([
            'Ligne 2, colonne code_client : C 01 n\'est pas un code valide (lettres, chiffres, tirets et tirets bas, 30 caractères au plus)',
            'Ligne 3, colonne categorie : catégorie inconnue : GROSSISTE',
            'Ligne 3, colonne code_region : la région SUD est inactive',
            'Ligne 4, colonne latitude : 95 n\'est pas une latitude valide (de -90 à 90)',
            'Ligne 5, colonne telephone : 30 caractères au plus',
            'Ligne 5, colonne longitude : renseignez la latitude et la longitude ensemble',
            'Ligne 6, colonne raison_sociale : la formule renvoie une erreur (#DIV/0!)',
        ]);
        expect($inactive->exists)->toBeTrue();
    });
});

it('reads formulas as their computed values', function () {
    $rows = [
        ['code_client', 'raison_sociale', 'code_region'],
        ['="C0"&"400"', '=CONCATENATE("Superette ", "Ennour")', 'CENTRE'],
    ];
    $id = ($this->upload)($rows)->json('id');

    $this->getJson("/api/v1/imports/{$id}")
        ->assertJsonPath('status', 'READY')
        ->assertJsonPath('preview.0.key', 'C0400')
        ->assertJsonPath('preview.0.changes.name.new', 'Superette Ennour');
});
