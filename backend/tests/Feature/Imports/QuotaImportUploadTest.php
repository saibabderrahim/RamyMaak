<?php

use App\Domain\Access\Role;
use App\Domain\Imports\ImportMode;
use App\Domain\Imports\ImportStatus;
use App\Domain\Imports\ImportType;
use App\Jobs\AnalyzeImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Imports\QuotaImportFixtures;
use Tests\Feature\Imports\Workbook;

// Dépôt d'un fichier de quotas (§8.1, contrat §5) : journée ouverte et mode
// obligatoires, droit quota.edit (l'ADV par défaut), contrôlé avant la
// validation ; les autres types d'import n'en sont pas affectés.

beforeEach(function () {
    QuotaImportFixtures::install($this);

    $this->rows = [QuotaImportFixtures::HEADER, ['P0042', 'Jus orange 1 L', 480, null, null]];

    $this->send = fn (array $payload, ?User $user = null) => $this->actingAs($user ?? $this->adv)
        ->post('/api/v1/imports', $payload);

    $this->payload = fn (array $overrides = []) => [
        'type' => 'QUOTAS',
        'business_day' => QuotaImportFixtures::DAY,
        'mode' => 'COMPLETE',
        'file' => Workbook::upload($this->rows, 'quotas.xlsx'),
        ...$overrides,
    ];
});

it('stores the day and the mode of a quota import and queues its analysis', function () {
    Queue::fake();

    ($this->send)(($this->payload)())
        ->assertStatus(202)
        ->assertJsonPath('type', 'QUOTAS')
        ->assertJsonPath('type_label', 'Quotas')
        ->assertJsonPath('business_day', '2026-10-08')
        ->assertJsonPath('mode', 'COMPLETE')
        ->assertJsonPath('status', 'PENDING')
        ->assertJsonPath('user.id', $this->adv->id);

    $import = Import::query()->sole();
    expect($import->type)->toBe(ImportType::Quotas)
        ->and($import->business_day_id)->toBe($this->day->id)
        ->and($import->mode)->toBe(ImportMode::Complete)
        ->and($import->status)->toBe(ImportStatus::Pending);
    Storage::disk('local')->assertExists($import->file_path);
    Queue::assertPushed(AnalyzeImport::class, fn (AnalyzeImport $job) => $job->import->is($import));
});

it('requires the day and the mode of a quota import', function () {
    $response = ($this->send)(['type' => 'QUOTAS', 'file' => Workbook::upload($this->rows)]);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.business_day', ['Le champ journée est obligatoire.'])
        ->assertJsonPath('errors.mode', ['Le champ mode est obligatoire.']);
    expect(Import::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a day that is malformed, not opened or closed', function (string $day, string $message) {
    $response = ($this->send)(($this->payload)(['business_day' => $day]));

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.business_day', [$message])->assertJsonMissingPath('errors.mode');
    expect(Import::query()->count())->toBe(0);
})->with([
    'malformed' => ['08/10/2026', 'Date attendue au format AAAA-MM-JJ.'],
    'not opened' => ['2026-10-09', 'La journée du 09/10/2026 n\'est pas ouverte.'],
]);

it('refuses a closed day', function () {
    $this->calendar->close($this->day);

    $response = ($this->send)(($this->payload)());

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.business_day', ['La journée du 08/10/2026 est clôturée : ses quotas ne sont plus modifiables.']);
    expect(Import::query()->count())->toBe(0);
});

it('refuses an unknown mode', function (mixed $mode) {
    $response = ($this->send)(($this->payload)(['mode' => $mode]));

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.mode', ['Mode attendu : REPLACE (remplacer) ou COMPLETE (compléter).']);
})->with(['REMPLACER', 'replace', 'FILL']);

it('requires quota.edit before any validation', function (User $user) {
    expectProblem(($this->send)(['type' => 'QUOTAS'], $user), 403, 'FORBIDDEN');
    expectProblem(($this->send)(($this->payload)(), $user), 403, 'FORBIDDEN');
    expect(Import::query()->count())->toBe(0);
})->with([
    'regional' => fn () => userWithRole(Role::Regional),
    // L'Admin n'a pas quota.edit par défaut (contrat §2) : il importe les référentiels seulement.
    'admin' => fn () => admin(),
]);

it('ignores a day and a mode sent with another type', function () {
    Queue::fake();

    $this->actingAs(admin())->post('/api/v1/imports', [
        'type' => 'CLIENTS',
        'business_day' => 'pas une date',
        'mode' => 'REPLACE',
        'file' => Workbook::upload([['code_client', 'raison_sociale', 'code_region'], ['C0001', 'Client A', 'CENTRE']]),
    ])->assertStatus(202)
        ->assertJsonPath('business_day', null)
        ->assertJsonPath('mode', null);

    expect(Import::query()->sole())
        ->business_day_id->toBeNull()
        ->mode->toBeNull();
});

describe('history', function () {
    beforeEach(function () {
        $this->admin = admin();
        $this->quotaImport = Import::factory()->create([
            'type' => ImportType::Quotas, 'business_day_id' => $this->day->id, 'mode' => ImportMode::Replace,
            'user_id' => $this->adv->id, 'original_name' => 'quotas.xlsx',
        ]);
        $this->clientsImport = Import::factory()->create(['type' => ImportType::Clients, 'user_id' => $this->admin->id]);
        $this->otherAdvImport = Import::factory()->create([
            'type' => ImportType::Quotas, 'business_day_id' => $this->day->id, 'mode' => ImportMode::Complete,
            'user_id' => userWithRole(Role::Adv)->id,
        ]);
    });

    it('lists the own imports of the default ADV, who may import quotas', function () {
        $this->actingAs($this->adv)->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->quotaImport->id])
            ->assertJsonPath('data.0.business_day', '2026-10-08')
            ->assertJsonPath('data.0.mode', 'REPLACE');

        $this->getJson("/api/v1/imports/{$this->quotaImport->id}")->assertOk()->assertJsonPath('business_day', '2026-10-08');
        expectProblem($this->getJson("/api/v1/imports/{$this->otherAdvImport->id}"), 404, 'NOT_FOUND');
    });

    it('lists every import for the Admin, filtered by type', function () {
        $this->actingAs($this->admin)->getJson('/api/v1/imports?filter[type]=QUOTAS')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->otherAdvImport->id, $this->quotaImport->id])
            ->assertJsonPath('data.1.mode', 'REPLACE');

        $this->getJson('/api/v1/imports?filter[type]=CLIENTS')
            ->assertJsonPath('data.*.id', [$this->clientsImport->id])
            ->assertJsonPath('data.0.business_day', null)
            ->assertJsonPath('data.0.mode', null);

        $this->getJson('/api/v1/imports?filter[type]=quotas')->assertJsonPath('meta.total', 2);
    });

    it('lets the Admin read a quota import but not apply it without quota.edit', function () {
        $this->actingAs($this->admin)->getJson("/api/v1/imports/{$this->quotaImport->id}")->assertOk();
        expectProblem($this->postJson("/api/v1/imports/{$this->quotaImport->id}/apply"), 403, 'FORBIDDEN');
    });
});
