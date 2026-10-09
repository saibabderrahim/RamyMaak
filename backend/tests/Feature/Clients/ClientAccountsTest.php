<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = admin();

    // Données remises à la fiche PDF : seul moyen de relire les mots de passe temporaires.
    $this->sheets = [];
    View::composer('pdf.client-access', function ($view) {
        $this->sheets = $view->getData()['sheets'];
    });

    $this->pageCount = fn (TestResponse $response): int => preg_match_all('#/Type\s*/Page[^s]#', $response->getContent());
});

it('is reserved to account managers, before any validation', function (Role $role) {
    $this->actingAs(userWithRole($role));
    $client = Client::factory()->create();

    expectProblem($this->postJson('/api/v1/admin/client-accounts', []), 403, 'FORBIDDEN');
    expectProblem($this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$client->id]]), 403, 'FORBIDDEN');
    expect($client->accounts()->exists())->toBeFalse();
})->with([Role::Adv, Role::Regional, Role::Client]);

it('creates one account per client and returns the access sheets as a PDF (EF-ADM-02)', function () {
    $this->actingAs($this->admin);
    $a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'trade_name' => 'El Baraka', 'phone' => '0550123456']);
    $b = Client::factory()->blocked()->create(['code' => 'G-07', 'name' => 'Grossiste Amine']);

    $response = $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$b->id, $a->id, $a->id]]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="fiches-acces-'.BusinessClock::today()->format('Ymd').'.pdf"')
        ->assertHeader('X-Accounts-Created', '2');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toStartWith('%PDF-')
        ->and(($this->pageCount)($response))->toBe(2);

    // Une fiche par compte, dans l'ordre des codes clients.
    expect(collect($this->sheets)->pluck('username')->all())->toBe(['c0012', 'g-07']);

    foreach ($this->sheets as $sheet) {
        $account = User::query()->where('username', $sheet['username'])->sole();
        $client = $account->client;
        expect($account->hasAppRole(Role::Client))->toBeTrue()
            ->and($account->must_change_password)->toBeTrue()
            ->and($account->is_active)->toBeTrue()
            ->and($account->name)->toBe($client->name)
            ->and($sheet['code'])->toBe($client->code)
            ->and($sheet['name'])->toBe($client->name)
            ->and($sheet['password'])->toMatch('/^[A-Za-z2-9]{10}$/')
            ->and(Hash::check($sheet['password'], $account->password))->toBeTrue();
    }
    expect(User::where('username', 'c0012')->sole()->phone)->toBe('0550123456');

    // Le mot de passe temporaire ouvre une session qui exige d'abord son changement (EF-AUTH-02).
    forgetGuards();
    $this->postJson('/api/v1/auth/logout');
    forgetGuards();
    $this->postJson('/api/v1/auth/login', ['username' => 'C0012', 'password' => $this->sheets[0]['password']])
        ->assertOk()
        ->assertJsonPath('must_change_password', true);
});

it('prints the company, the credentials, the address and the install help', function () {
    $this->actingAs($this->admin);
    config(['app.url' => 'https://commandes.ramy.dz/']);
    $client = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'trade_name' => 'El Baraka']);

    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$client->id]])->assertOk();

    $html = view('pdf.client-access', [
        'sheets' => $this->sheets,
        'appUrl' => 'https://commandes.ramy.dz',
        'issuedOn' => BusinessClock::today(),
    ])->render();

    expect($html)->toContain('Superette El Baraka', 'El Baraka', 'C0012', 'c0012', $this->sheets[0]['password'], 'https://commandes.ramy.dz')
        ->toContain(e(__('clients.access_sheet.android_title')), e(__('clients.access_sheet.ios_title')))
        ->toContain(e(__('clients.access_sheet.ios_steps')[2]))
        ->toContain(e(__('clients.access_sheet.issued_on', ['date' => BusinessClock::today()->format('d/m/Y')])));
});

it('skips clients that already have an account, inactive clients and taken usernames', function () {
    $this->actingAs($this->admin);
    $withAccount = Client::factory()->create(['code' => 'C1']);
    userWithRole(Role::Client, ['username' => 'c1-gerant', 'client_id' => $withAccount->id, 'is_active' => false]);
    $inactive = Client::factory()->inactive()->create(['code' => 'C2']);
    $taken = Client::factory()->create(['code' => 'K.SAIDI']);
    userWithRole(Role::Regional, ['username' => 'k.saidi']);
    $fresh = Client::factory()->create(['code' => 'C4']);

    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$withAccount->id, $inactive->id, $taken->id, $fresh->id]])
        ->assertOk()
        ->assertHeader('X-Accounts-Created', '1');

    expect(collect($this->sheets)->pluck('username')->all())->toBe(['c4'])
        ->and($withAccount->accounts()->count())->toBe(1)
        ->and($inactive->accounts()->count())->toBe(0)
        ->and($taken->accounts()->count())->toBe(0)
        ->and(User::where('username', 'k.saidi')->sole()->client_id)->toBeNull();
});

it('answers 422 when no account could be created', function () {
    $this->actingAs($this->admin);
    $inactive = Client::factory()->inactive()->create();
    $withAccount = Client::factory()->create();
    userWithRole(Role::Client, ['client_id' => $withAccount->id]);
    $before = User::count();

    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$inactive->id, $withAccount->id]])
        ->assertJsonValidationErrors(['client_ids' => __('clients.accounts.none_created')]);

    // Une seconde demande pour les mêmes clients ne crée rien de plus.
    $created = Client::factory()->create();
    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$created->id]])->assertOk();
    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$created->id]])->assertJsonValidationErrors('client_ids');

    expect(User::count())->toBe($before + 1);
});

it('validates the client list', function (mixed $ids, string $field) {
    $this->actingAs($this->admin);
    Client::factory()->create();

    $this->postJson('/api/v1/admin/client-accounts', $ids === '__missing__' ? [] : ['client_ids' => $ids])
        ->assertJsonValidationErrors($field);
})->with([
    'missing' => ['__missing__', 'client_ids'],
    'empty' => [[], 'client_ids'],
    'not a list' => ['12', 'client_ids'],
    'not integers' => [['abc'], 'client_ids.0'],
    'nested' => [[[1]], 'client_ids.0'],
    'unknown client' => [[999999], 'client_ids'],
    'more than 200' => [range(1, 201), 'client_ids'],
]);

it('journals each created account in one batch, without the password', function () {
    $this->actingAs($this->admin);
    $clients = Client::factory()->count(3)->create();

    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => $clients->pluck('id')->all()])->assertOk();

    $entries = AuditLog::query()->where('event', 'client-account.created')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->pluck('log_name')->unique()->all())->toBe(['users'])
        ->and($entries->pluck('batch_uuid')->filter()->unique())->toHaveCount(1)
        ->and($entries->pluck('properties.attributes.client_id')->sort()->values()->all())->toBe($clients->pluck('id')->sort()->values()->all());

    $everything = AuditLog::query()->get()->toJson();
    foreach ($this->sheets as $sheet) {
        expect($everything)->not->toContain($sheet['password']);
    }
});

it('creates nothing when the PDF cannot be produced', function () {
    $this->actingAs($this->admin);
    $client = Client::factory()->create();
    View::composer('pdf.client-access', fn () => throw new RuntimeException('Rendu impossible'));

    $this->postJson('/api/v1/admin/client-accounts', ['client_ids' => [$client->id]])->assertStatus(500);

    expect($client->accounts()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'client-account.created')->exists())->toBeFalse();
});
