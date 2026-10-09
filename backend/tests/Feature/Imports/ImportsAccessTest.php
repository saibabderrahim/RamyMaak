<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Imports\ImportStatus;
use App\Domain\Imports\ImportType;
use App\Models\Client;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Feature\Imports\Workbook;

// Droits sur les imports (§3.2, contrat §2) : droit du type pour déposer,
// appliquer et télécharger un gabarit ; lecture par l'auteur ou l'Admin.

beforeEach(function () {
    Storage::fake('local');
    $this->admin = admin();
    Region::factory()->create(['code' => 'CENTRE']);
    ProductFamily::factory()->create(['code' => 'JUS']);

    // ADV autorisé à gérer le catalogue (option du §3.2).
    $this->catalogAdv = function (): User {
        SpatieRole::findByName(Role::Adv->value, 'web')->givePermissionTo(Permission::ProductManage->value);

        return userWithRole(Role::Adv);
    };

    $this->clientsFile = fn () => Workbook::upload([
        ['code_client', 'raison_sociale', 'code_region'],
        ['C0001', 'Client A', 'CENTRE'],
    ], 'clients.xlsx');

    $this->productsFile = fn () => Workbook::upload([
        ['code_produit', 'designation', 'code_famille', 'libelle_uv', 'unites_par_uv', 'taux_tva'],
        ['P0001', 'Jus orange 1 L', 'JUS', 'Fardeau 6 × 1 L', 6, 19],
    ], 'produits.xlsx');
});

describe('upload and application rights', function () {
    it('refuses the regional, the client and the default ADV before any validation', function (Role $role) {
        $this->actingAs(userWithRole($role));

        expectProblem($this->post('/api/v1/imports', []), 403, 'FORBIDDEN');
        expectProblem($this->post('/api/v1/imports', ['type' => 'PRODUCTS', 'file' => ($this->productsFile)()]), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/imports'), 403, 'FORBIDDEN');
        expectProblem($this->get('/api/v1/imports/templates/clients'), 403, 'FORBIDDEN');
        expect(Import::query()->count())->toBe(0);
    })->with([Role::Regional, Role::Client, Role::Adv]);

    it('checks the right of the type: client.manage for clients, product.manage for products and prices', function () {
        $adv = ($this->catalogAdv)();
        $this->actingAs($adv);

        expectProblem($this->post('/api/v1/imports', ['type' => 'CLIENTS', 'file' => ($this->clientsFile)()]), 403, 'FORBIDDEN');
        // Droit contrôlé avant la validation du fichier.
        expectProblem($this->post('/api/v1/imports', ['type' => 'CLIENTS']), 403, 'FORBIDDEN');
        expectProblem($this->get('/api/v1/imports/templates/clients'), 403, 'FORBIDDEN');

        $this->post('/api/v1/imports', ['type' => 'PRODUCTS', 'file' => ($this->productsFile)()])->assertStatus(202);
        $this->get('/api/v1/imports/templates/products')->assertOk();
        $this->get('/api/v1/imports/templates/prices')->assertOk();

        expect(Import::query()->pluck('type')->all())->toBe([ImportType::Products]);
    });

    it('lets the author or the Admin apply, with the right of the type', function () {
        $adv = ($this->catalogAdv)();
        $id = $this->actingAs($adv)->post('/api/v1/imports', ['type' => 'PRODUCTS', 'file' => ($this->productsFile)()])->json('id');

        // Un autre utilisateur habilité ne voit pas l'import d'autrui.
        $otherAdv = userWithRole(Role::Adv);
        expectProblem($this->actingAs($otherAdv)->postJson("/api/v1/imports/{$id}/apply"), 404, 'NOT_FOUND');

        // L'Admin peut appliquer l'import d'un autre.
        $this->actingAs($this->admin)->postJson("/api/v1/imports/{$id}/apply")
            ->assertOk()
            ->assertJsonPath('status', 'APPLIED')
            ->assertJsonPath('user.id', $adv->id);
        expect(Product::query()->where('sku', 'P0001')->exists())->toBeTrue();
    });

    it('refuses the application to an author who lost the right of the type', function () {
        $adv = ($this->catalogAdv)();
        $id = $this->actingAs($adv)->post('/api/v1/imports', ['type' => 'PRODUCTS', 'file' => ($this->productsFile)()])->json('id');

        SpatieRole::findByName(Role::Adv->value, 'web')->revokePermissionTo(Permission::ProductManage->value);
        forgetGuards();

        expectProblem($this->actingAs($adv->fresh())->postJson("/api/v1/imports/{$id}/apply"), 403, 'FORBIDDEN');
        expect(Import::query()->find($id)->status)->toBe(ImportStatus::Ready);
    });
});

describe('reading', function () {
    beforeEach(function () {
        $this->adv = ($this->catalogAdv)();
        $this->advImport = Import::factory()->create(['type' => ImportType::Products, 'user_id' => $this->adv->id, 'original_name' => 'produits.xlsx']);
        $this->adminImport = Import::factory()->create(['type' => ImportType::Clients, 'user_id' => $this->admin->id, 'status' => ImportStatus::Invalid]);
        $this->otherAdmin = admin();
    });

    it('shows an import to its author and to the Admin only', function () {
        $this->actingAs($this->adv)->getJson("/api/v1/imports/{$this->advImport->id}")
            ->assertOk()
            ->assertJsonPath('original_name', 'produits.xlsx');
        $this->actingAs($this->otherAdmin)->getJson("/api/v1/imports/{$this->advImport->id}")->assertOk();

        // Hors de portée : 404, comme un import inexistant.
        $unknown = $this->actingAs($this->adv)->getJson('/api/v1/imports/999999');
        expectProblem($unknown, 404, 'NOT_FOUND');
        $hidden = $this->actingAs($this->adv)->getJson("/api/v1/imports/{$this->adminImport->id}");
        expectProblem($hidden, 404, 'NOT_FOUND');
        expect($hidden->json())->toBe($unknown->json());

        expectProblem($this->actingAs($this->adv)->get("/api/v1/imports/{$this->adminImport->id}/error-report"), 404, 'NOT_FOUND');
        expectProblem($this->actingAs(userWithRole(Role::Regional))->getJson("/api/v1/imports/{$this->advImport->id}"), 404, 'NOT_FOUND');
    });

    it('lists the own imports, every import for the Admin, newest first', function () {
        $this->travel(1)->minutes();
        $latest = Import::factory()->create(['type' => ImportType::Prices, 'user_id' => $this->adv->id, 'status' => ImportStatus::Applied]);

        $this->actingAs($this->adv)->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$latest->id, $this->advImport->id])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->actingAs($this->otherAdmin)->getJson('/api/v1/imports')
            ->assertJsonPath('data.*.id', [$latest->id, $this->adminImport->id, $this->advImport->id]);

        $this->getJson('/api/v1/imports?sort=created_at')
            ->assertJsonPath('data.*.id', [$this->advImport->id, $this->adminImport->id, $latest->id]);
    });

    it('filters by type and status', function () {
        $this->actingAs($this->admin);

        $this->getJson('/api/v1/imports?filter[type]=CLIENTS')->assertJsonPath('data.*.id', [$this->adminImport->id]);
        $this->getJson('/api/v1/imports?filter[status]=PENDING')->assertJsonPath('data.*.id', [$this->advImport->id]);
        $this->getJson('/api/v1/imports?filter[type]=PRODUCTS&filter[status]=INVALID')->assertJsonPath('meta.total', 0);

        expectProblem($this->getJson('/api/v1/imports?filter[type]=QUOTAS'), 422, 'VALIDATION_FAILED');
        expectProblem($this->getJson('/api/v1/imports?filter[status]=DONE'), 422, 'VALIDATION_FAILED');
        expectProblem($this->getJson('/api/v1/imports?filter[user_id]=1'), 422, 'VALIDATION_FAILED');
        expectProblem($this->getJson('/api/v1/imports?sort=status'), 422, 'VALIDATION_FAILED');
    });

    it('answers 404 when there is no error report', function () {
        expectProblem($this->actingAs($this->admin)->get("/api/v1/imports/{$this->adminImport->id}/error-report"), 404, 'NOT_FOUND');
    });

    it('requires an authenticated session', function () {
        expectProblem($this->getJson('/api/v1/imports'), 401, 'UNAUTHENTICATED');
        expectProblem($this->postJson("/api/v1/imports/{$this->advImport->id}/apply"), 401, 'UNAUTHENTICATED');
    });
});

it('applies only a READY import', function (ImportStatus $status, int $httpStatus, string $code) {
    $import = Import::factory()->create(['user_id' => $this->admin->id, 'status' => $status]);

    expectProblem($this->actingAs($this->admin)->postJson("/api/v1/imports/{$import->id}/apply"), $httpStatus, $code);
    expect($import->fresh()->status)->toBe($status)
        ->and(Client::query()->count())->toBe(0);
})->with([
    'pending' => [ImportStatus::Pending, 409, 'INVALID_TRANSITION'],
    'analyzing' => [ImportStatus::Analyzing, 409, 'INVALID_TRANSITION'],
    'invalid' => [ImportStatus::Invalid, 409, 'IMPORT_HAS_ERRORS'],
    'applied' => [ImportStatus::Applied, 409, 'INVALID_TRANSITION'],
    'failed' => [ImportStatus::Failed, 409, 'INVALID_TRANSITION'],
]);
