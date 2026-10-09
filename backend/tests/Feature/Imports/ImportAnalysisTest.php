<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Imports\ClientsDefinition;
use App\Domain\Imports\ImportProcessor;
use App\Domain\Imports\ImportStatus;
use App\Jobs\AnalyzeImport;
use App\Models\Client;
use App\Models\Import;
use App\Models\Region;
use App\Models\RegionAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Feature\Imports\Workbook;

// Analyse d'un fichier (§8.1 étapes 2-3) : en-tête, limites du rapport,
// progression, incidents techniques, droits de l'auteur sur chaque ligne.

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));
    $this->admin = admin();
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);

    $this->upload = fn (array $rows, $user = null) => $this->actingAs($user ?? $this->admin)
        ->post('/api/v1/imports', ['type' => 'CLIENTS', 'file' => Workbook::upload($rows, 'clients.xlsx')]);

    $this->report = fn (int $id) => $this->getJson("/api/v1/imports/{$id}")->json();

    // Lignes de clients à créer : C0001, C0002…
    $this->clientRows = function (int $count, ?string $email = null): array {
        $rows = [['code_client', 'raison_sociale', 'code_region', 'email']];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [sprintf('C%04d', $i), "Client {$i}", 'CENTRE', $email];
        }

        return $rows;
    };
});

describe('header', function () {
    it('ignores case, accents, spaces and unknown columns', function () {
        $id = ($this->upload)([
            ['  CODE CLIENT ', 'Raison Sociale *', 'Code-Région', 'Remarque', 'Statut'],
            ['C0001', 'Client A', 'CENTRE', 'à appeler', 'Actif'],
        ])->json('id');

        expect(($this->report)($id))
            ->status->toBe('READY')
            ->summary->toBe(['create' => 1, 'update' => 0, 'unchanged' => 0]);
    });

    it('reads the first sheet only', function () {
        $id = $this->actingAs($this->admin)->post('/api/v1/imports', [
            'type' => 'CLIENTS',
            'file' => Workbook::upload([
                ['code_client', 'raison_sociale', 'code_region'],
                ['C0001', 'Client A', 'CENTRE'],
            ], 'clients.xlsx', function ($sheet) {
                $notes = $sheet->getParent()->createSheet();
                $notes->setTitle('Notes');
                $notes->fromArray([['code_client', 'raison_sociale', 'code_region'], ['???', null, 'NULLE PART']]);
            }),
        ])->json('id');

        expect(($this->report)($id))
            ->status->toBe('READY')
            ->rows_total->toBe(1);
    });

    it('refuses a column given twice', function () {
        $id = ($this->upload)([
            ['code_client', 'raison_sociale', 'code_region', 'Raison sociale'],
            ['C0001', 'Client A', 'CENTRE', 'Client A bis'],
        ])->json('id');

        expect(($this->report)($id))
            ->status->toBe('INVALID')
            ->errors->toBe([['row' => null, 'column' => 'raison_sociale', 'message' => 'Colonne raison_sociale : colonne présente plusieurs fois']]);
    });

    it('refuses a file without data rows', function () {
        $id = ($this->upload)([['code_client', 'raison_sociale', 'code_region'], [null, null, null]])->json('id');

        expect(($this->report)($id))
            ->status->toBe('INVALID')
            ->rows_total->toBe(0)
            ->errors->toBe([['row' => null, 'column' => null, 'message' => 'Le fichier ne contient aucune ligne de données.']]);
    });
});

describe('report limits', function () {
    it('keeps the first 500 errors and complete counters; the annotated file has them all', function () {
        $id = ($this->upload)(($this->clientRows)(501, 'pas-un-email'))->json('id');

        $report = ($this->report)($id);
        expect($report['status'])->toBe('INVALID')
            ->and($report['rows_total'])->toBe(501)
            ->and($report['rows_error'])->toBe(501)
            ->and($report['rows_ok'])->toBe(0)
            ->and($report['errors'])->toHaveCount(500)
            ->and($report['errors_truncated'])->toBeTrue()
            ->and($report['errors'][499]['row'])->toBe(501);

        $sheet = Workbook::values($this->get("/api/v1/imports/{$id}/error-report")->streamedContent());
        expect($sheet[502][4])->toBe('Ligne 502, colonne email : pas-un-email n\'est pas une adresse e-mail valide');
    });

    it('previews the first 200 changed rows with complete counters', function () {
        $id = ($this->upload)(($this->clientRows)(201))->json('id');

        $report = ($this->report)($id);
        expect($report['status'])->toBe('READY')
            ->and($report['summary'])->toBe(['create' => 201, 'update' => 0, 'unchanged' => 0])
            ->and($report['preview'])->toHaveCount(200)
            ->and($report['preview_truncated'])->toBeTrue()
            ->and($report['preview'][199]['key'])->toBe('C0200');

        $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();
        expect(Client::query()->count())->toBe(201);
    });
});

it('updates the progress while analysing', function () {
    $progress = [];
    DB::listen(function (QueryExecuted $query) use (&$progress) {
        // Guillemets SQLite ou accents graves MySQL.
        if (preg_match('/^update [`"]imports[`"] set [`"]rows_total[`"]/', $query->sql) === 1) {
            $progress[] = array_slice($query->bindings, 0, 2);
        }
    });

    $id = ($this->upload)(($this->clientRows)(250))->json('id');

    expect($progress)->toBe([[250, 0], [250, 100], [250, 200]])
        ->and(($this->report)($id))->rows_processed->toBe(250);
});

describe('incidents', function () {
    it('marks the import FAILED with a generic message on an unexpected error', function () {
        app()->bind(ClientsDefinition::class, fn () => throw new RuntimeException('Connexion perdue (détail technique)'));

        $id = ($this->upload)(($this->clientRows)(2))->assertStatus(202)->json('id');

        $report = ($this->report)($id);
        expect($report['status'])->toBe('FAILED')
            ->and($report['failure_message'])->toBe('L\'analyse du fichier a été interrompue par un incident technique. Déposez à nouveau le fichier ; si le problème persiste, contactez l\'administrateur.')
            ->and($report['analyzed_at'])->not->toBeNull();
        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'INVALID_TRANSITION');
    });

    it('reports a stored file that cannot be read as an invalid file', function () {
        Queue::fake();
        $id = ($this->upload)(($this->clientRows)(1))->json('id');
        $import = Import::query()->findOrFail($id);
        Storage::disk('local')->put($import->file_path, 'PK corrompu');

        (new AnalyzeImport($import))->handle(app(ImportProcessor::class));

        expect(($this->report)($id))
            ->status->toBe('INVALID')
            ->has_error_report->toBeFalse()
            ->errors->toBe([['row' => null, 'column' => null, 'message' => 'Fichier illisible : enregistrez-le au format « Classeur Excel (.xlsx) » puis déposez-le à nouveau.']]);
    });

    it('does not analyse an import twice', function () {
        $id = ($this->upload)(($this->clientRows)(1))->json('id');
        $import = Import::query()->findOrFail($id);
        $analyzedAt = $import->analyzed_at;
        $this->travel(5)->minutes();

        AnalyzeImport::dispatchSync($import);

        expect($import->fresh())
            ->status->toBe(ImportStatus::Ready)
            ->analyzed_at->toEqual($analyzedAt);
    });

    it('leaves no import stuck in analysis when the job fails', function () {
        Queue::fake();
        $id = ($this->upload)(($this->clientRows)(1))->json('id');
        $import = Import::query()->findOrFail($id);

        (new AnalyzeImport($import))->failed(new RuntimeException('Délai dépassé'));
        expect($import->fresh()->status)->toBe(ImportStatus::Failed);

        // Un import déjà analysé n'est pas touché.
        $ready = Import::factory()->create(['status' => ImportStatus::Ready]);
        (new AnalyzeImport($ready))->failed(null);
        expect($ready->fresh()->status)->toBe(ImportStatus::Ready);
    });

});

describe('rights of the author on each row', function () {
    beforeEach(function () {
        // ADV autorisé à gérer les clients, sans droit de blocage, limité au Centre (RG-S05).
        SpatieRole::findByName(Role::Adv->value, 'web')->givePermissionTo(Permission::ClientManage->value);
        $this->adv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $this->adv->id, 'region_id' => $this->centre->id]);
        Client::factory()->create(['code' => 'C0100', 'region_id' => $this->centre->id]);
        Client::factory()->create(['code' => 'C0200', 'region_id' => $this->est->id]);
    });

    it('refuses rows outside the perimeter of the author and status changes without client.block', function () {
        $id = ($this->upload)([
            ['code_client', 'raison_sociale', 'code_region', 'statut'],
            ['C0100', 'Client du Centre', 'CENTRE', 'BLOQUE'],
            ['C0200', 'Client de l\'Est', 'EST', null],
            ['C0300', 'Nouveau client', 'EST', null],
            ['C0400', 'Autre client', 'CENTRE', 'ACTIF'],
        ], $this->adv)->assertStatus(202)->json('id');

        expect(collect(($this->report)($id)['errors'])->pluck('message')->all())->toBe([
            'Ligne 2, colonne statut : vous n\'avez pas le droit de changer le statut d\'un client',
            'Ligne 3, colonne code_client : client C0200 hors de votre périmètre',
            'Ligne 4, colonne code_region : région EST hors de votre périmètre',
        ]);
    });
});

it('checks the other cells of a row whose key is invalid', function () {
    $id = ($this->upload)([
        ['code_client', 'raison_sociale', 'code_region', 'email'],
        [null, 'Client sans code', 'NORD', 'pas-un-email'],
    ])->json('id');

    expect(collect(($this->report)($id)['errors'])->pluck('message')->all())->toBe([
        'Ligne 2, colonne code_client : valeur obligatoire',
        'Ligne 2, colonne email : pas-un-email n\'est pas une adresse e-mail valide',
        'Ligne 2, colonne code_region : code région inconnu : NORD',
    ]);
});
