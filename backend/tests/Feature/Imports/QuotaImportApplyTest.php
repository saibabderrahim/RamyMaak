<?php

use App\Domain\Access\Role;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Imports\ImportStatus;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\AuditLog;
use App\Models\Import;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Imports\QuotaImportFixtures;

// Application d'un import de quotas (§8.1 étapes 3 à 6, contrat §5) : aperçu
// par produit et par code région, application en une transaction par
// QuotaBook::saveDrafts, brouillons seulement (RG-Q09), revalidation.

beforeEach(function () {
    QuotaImportFixtures::install($this);

    // CENTRE P0042 450 → 480 ; OUEST P0103 0 → 40 ; cellules vides ailleurs.
    $this->file = [
        QuotaImportFixtures::HEADER,
        ['P0042', 'Jus orange 1 L', 480, null, null],
        ['P0103', 'Lben 1 L', null, null, 40],
    ];

    $this->before = QuotaImportFixtures::cells($this);
});

it('replaces the values of the regions of the file, empty cells and absent products worth 0', function () {
    // L'EST ne figure pas dans ce fichier : ses quotas ne changent pas.
    $file = [
        ['code_produit', 'designation (information)', 'CENTRE', 'OUEST'],
        ['P0042', 'Jus orange 1 L', 480, null],
        ['P0103', 'Lben 1 L', null, 40],
    ];
    $id = ($this->upload)($file, 'REPLACE')->assertStatus(202)->json('id');

    expect(($this->report)($id))
        ->status->toBe('READY')
        ->business_day->toBe('2026-10-08')
        ->mode->toBe('REPLACE')
        ->rows_total->toBe(2)
        ->rows_ok->toBe(2)
        ->summary->toBe(['create' => 0, 'update' => 3, 'unchanged' => 0])
        ->preview->toBe([
            ['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 450, 'new' => 480]]],
            ['row' => 3, 'key' => 'P0103', 'action' => 'update', 'changes' => ['OUEST' => ['old' => 0, 'new' => 40]]],
            // P0057, absent du fichier : 0 au Centre.
            ['row' => null, 'key' => 'P0057', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 300, 'new' => 0]]],
        ]);
    // Rien n'est écrit avant l'application.
    expect(QuotaImportFixtures::cells($this))->toBe($this->before);

    $this->postJson("/api/v1/imports/{$id}/apply")
        ->assertOk()
        ->assertJsonPath('status', 'APPLIED')
        ->assertJsonPath('mode', 'REPLACE');

    // Brouillons seulement : quotas publiés inchangés (RG-Q09).
    expect(QuotaImportFixtures::cells($this))->toBe([
        'CENTRE|P0042' => [500, 480, 0],
        'CENTRE|P0057' => [300, 0, 0],
        'EST|P0042' => [200, null, 0],
        'OUEST|P0103' => [0, 40, 0],
    ])
        ->and(($this->regionDay)($this->centre))->status->toBe(RegionDayStatus::QuotaPublished)->quota_version->toBe(1)
        ->and(($this->regionDay)($this->ouest))->status->toBe(RegionDayStatus::QuotaDraft)->quota_version->toBe(0);

    Event::assertNotDispatched(QuotasPublished::class);
    Event::assertDispatchedTimes(QuotaDraftsSaved::class, 1);
    Event::assertDispatched(QuotaDraftsSaved::class, fn (QuotaDraftsSaved $event) => $event->businessDayId === $this->day->id
        && $event->regionIds === [$this->centre->id, $this->ouest->id]
        && $event->actorId === $this->adv->id);

    $entry = AuditLog::query()->where('log_name', 'imports')->sole();
    expect($entry->event)->toBe('applied')
        ->and($entry->causer_id)->toBe($this->adv->id)
        // Clés d'un objet JSON : leur ordre n'est pas garanti (MySQL les réordonne).
        ->and($entry->properties->all())->toEqual([
            'type' => 'QUOTAS',
            'file' => 'quotas.xlsx',
            'summary' => ['create' => 0, 'update' => 3, 'unchanged' => 0],
            'day' => '2026-10-08',
            'mode' => 'REPLACE',
        ])
        // Aucune publication : le journal des quotas ne contient que celle du jeu de données.
        ->and(AuditLog::query()->where('log_name', 'quotas')->count())->toBe(2);
});

it('completes only the filled cells', function () {
    $id = ($this->upload)($this->file, 'COMPLETE')->json('id');

    expect(($this->report)($id))
        ->status->toBe('READY')
        ->summary->toBe(['create' => 0, 'update' => 2, 'unchanged' => 0])
        ->preview->toBe([
            ['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 450, 'new' => 480]]],
            ['row' => 3, 'key' => 'P0103', 'action' => 'update', 'changes' => ['OUEST' => ['old' => 0, 'new' => 40]]],
        ]);

    $this->postJson("/api/v1/imports/{$id}/apply")->assertOk()->assertJsonPath('status', 'APPLIED');

    expect(QuotaImportFixtures::cells($this))->toBe([
        'CENTRE|P0042' => [500, 480, 0],
        'CENTRE|P0057' => [300, null, 0],
        'EST|P0042' => [200, null, 0],
        'OUEST|P0103' => [0, 40, 0],
    ]);
});

it('replaces every region of the file, the EST included', function () {
    $id = ($this->upload)($this->file, 'REPLACE')->json('id');

    expect(($this->report)($id))
        ->summary->toBe(['create' => 0, 'update' => 3, 'unchanged' => 0])
        ->preview->toBe([
            ['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => [
                'CENTRE' => ['old' => 450, 'new' => 480],
                'EST' => ['old' => 200, 'new' => 0],
            ]],
            ['row' => 3, 'key' => 'P0103', 'action' => 'update', 'changes' => ['OUEST' => ['old' => 0, 'new' => 40]]],
            ['row' => null, 'key' => 'P0057', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 300, 'new' => 0]]],
        ]);

    $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();
    expect(QuotaImportFixtures::cells($this))->toBe([
        'CENTRE|P0042' => [500, 480, 0],
        'CENTRE|P0057' => [300, 0, 0],
        'EST|P0042' => [200, 0, 0],
        'OUEST|P0103' => [0, 40, 0],
    ]);
});

it('cancels a pending draft when the file gives back the published value', function () {
    $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', 500], ['P0057', 300]], 'COMPLETE')->json('id');

    expect(($this->report)($id))
        ->summary->toBe(['create' => 0, 'update' => 1, 'unchanged' => 1])
        ->preview->toBe([['row' => 2, 'key' => 'P0042', 'action' => 'update', 'changes' => ['CENTRE' => ['old' => 450, 'new' => 500]]]]);

    $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();
    expect(QuotaImportFixtures::cells($this)['CENTRE|P0042'])->toBe([500, null, 0]);
});

it('applies an import without changes without writing anything', function () {
    $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0042', 450]], 'COMPLETE')->json('id');

    $this->postJson("/api/v1/imports/{$id}/apply")->assertOk()->assertJsonPath('status', 'APPLIED');

    expect(QuotaImportFixtures::cells($this))->toBe($this->before);
    Event::assertNotDispatched(QuotaDraftsSaved::class);
});

describe('revalidation at application', function () {
    it('refuses a product deactivated since the analysis', function () {
        $id = ($this->upload)($this->file, 'COMPLETE')->json('id');
        expect(($this->report)($id))->status->toBe('READY');

        $this->lben->forceFill(['is_active' => false])->save();

        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
        expect(($this->report)($id))
            ->status->toBe('INVALID')
            ->has_error_report->toBeTrue()
            ->errors->toBe([['row' => 3, 'column' => 'code_produit', 'message' => 'Ligne 3, colonne code_produit : code produit P0103 inconnu ou inactif']]);
        expect(QuotaImportFixtures::cells($this))->toBe($this->before)
            ->and(AuditLog::query()->where('log_name', 'imports')->count())->toBe(0);
        Event::assertNotDispatched(QuotaDraftsSaved::class);
    });

    it('refuses a value that fell below the validated counter since the analysis', function () {
        $id = ($this->upload)([['code_produit', 'CENTRE'], ['P0057', 250]], 'COMPLETE')->json('id');
        expect(($this->report)($id))->status->toBe('READY');

        ($this->validate)($this->centre, $this->pomme, 280);

        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
        expect(($this->report)($id)['errors'][0]['message'])->toBe('Ligne 2, colonne CENTRE : 250 inférieur aux 280 déjà validés');
    });

    it('answers DAY_NOT_OPEN when the day was closed since the analysis', function () {
        $id = ($this->upload)($this->file, 'COMPLETE')->json('id');
        $this->calendar->close($this->day);

        $response = $this->postJson("/api/v1/imports/{$id}/apply");
        expectProblem($response, 409, 'DAY_NOT_OPEN');
        $response->assertJsonPath('detail', 'La journée du 08/10/2026 est clôturée : ses quotas ne sont plus modifiables.');

        expect(($this->report)($id))
            ->status->toBe('INVALID')
            ->errors->toBe([['row' => null, 'column' => null, 'message' => 'La journée du 08/10/2026 est clôturée : ses quotas ne sont plus modifiables.']]);
        expect(QuotaImportFixtures::cells($this))->toBe($this->before);
        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'IMPORT_HAS_ERRORS');
    });
});

describe('rights', function () {
    it('lets another ADV neither see nor apply the import', function () {
        $id = ($this->upload)($this->file, 'COMPLETE')->json('id');

        expectProblem($this->actingAs(userWithRole(Role::Adv))->postJson("/api/v1/imports/{$id}/apply"), 404, 'NOT_FOUND');
        expect(Import::query()->findOrFail($id)->status)->toBe(ImportStatus::Ready);
    });

    it('applies only once', function () {
        $id = ($this->upload)($this->file, 'COMPLETE')->json('id');
        $this->postJson("/api/v1/imports/{$id}/apply")->assertOk();

        expectProblem($this->postJson("/api/v1/imports/{$id}/apply"), 409, 'INVALID_TRANSITION');
        Event::assertDispatchedTimes(QuotaDraftsSaved::class, 1);
    });
});
