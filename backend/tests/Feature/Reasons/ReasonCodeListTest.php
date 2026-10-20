<?php

use App\Domain\Access\Role;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// GET /reason-codes (EF-ADM-05 ; contrat du lot 6, §2 et §4) : motifs actifs
// des listes de choix, non paginés, par type puis ordre puis libellé ; le
// régional, l'ADV et l'Admin les lisent, le client reçoit 403.

beforeEach(function () {
    $this->users = fn (string $role): User => match ($role) {
        'regional' => userWithRole(Role::Regional),
        'adv' => userWithRole(Role::Adv),
        'admin' => admin(),
        'client' => userWithRole(Role::Client),
        'none' => User::factory()->create(),
    };
    $this->list = fn (string $query = '', string $role = 'regional') => $this->actingAs(($this->users)($role))->getJson('/api/v1/reason-codes'.$query);
    $this->option = fn (string $type, string $code, string $label, int $order, bool $comment = false) => [
        'type' => $type, 'code' => $code, 'label' => $label, 'requires_comment' => $comment, 'sort_order' => $order,
    ];
    $this->codes = fn ($response) => collect($response->json('data'))->map(fn (array $reason) => "{$reason['type']}.{$reason['code']}")->all();
});

describe('rights', function () {
    it('lets the regional, the ADV and the Admin read the reasons', function (string $role) {
        ($this->list)('', $role)->assertOk()->assertJsonCount(17, 'data');
    })->with(['regional', 'adv', 'admin']);

    it('refuses with 403 a client account and an account without role', function (string $role) {
        expectProblem(($this->list)('', $role), 403, 'FORBIDDEN');
    })->with(['client', 'none']);

    it('checks the role before the format of the type (403 before 422)', function () {
        expectProblem(($this->list)('?type=NOPE', 'client'), 403, 'FORBIDDEN');
    });

    it('requires an authenticated session', function () {
        expectProblem($this->getJson('/api/v1/reason-codes'), 401, 'UNAUTHENTICATED');
    });
});

describe('list', function () {
    it('gives the active reasons of a type, in the order of the contract, with the exact shape', function () {
        $response = ($this->list)('?type=ADJUSTMENT')->assertOk();

        expect($response->json())->toBe(['data' => [
            ($this->option)('ADJUSTMENT', 'QUOTA_INSUFFISANT', 'Quota insuffisant', 10),
            ($this->option)('ADJUSTMENT', 'BORNE_ATTEINTE', 'Borne du client atteinte', 20),
            ($this->option)('ADJUSTMENT', 'DEMANDE_CLIENT', 'À la demande du client', 30),
            ($this->option)('ADJUSTMENT', 'AUTRE', 'Autre', 40, true),
        ]]);
    });

    it('gives the reasons of several types, by type, then by order, then by label', function (string $query) {
        $codes = ($this->codes)(($this->list)($query)->assertOk());

        expect($codes)->toBe([
            'ADJUSTMENT.QUOTA_INSUFFISANT', 'ADJUSTMENT.BORNE_ATTEINTE', 'ADJUSTMENT.DEMANDE_CLIENT', 'ADJUSTMENT.AUTRE',
            'REJECTION.QUOTA_EPUISE', 'REJECTION.CLIENT_BLOQUE', 'REJECTION.DOUBLON', 'REJECTION.DEMANDE_CLIENT', 'REJECTION.AUTRE',
        ]);
    })->with([
        'liste' => '?type=ADJUSTMENT,REJECTION',
        'ordre inverse' => '?type=REJECTION,ADJUSTMENT',
        'casse indifférente' => '?type=rejection,Adjustment',
        'espaces' => '?type=ADJUSTMENT, REJECTION',
        'doublons' => '?type=ADJUSTMENT,REJECTION,ADJUSTMENT',
        'tableau' => '?type[]=REJECTION&type[]=ADJUSTMENT',
    ]);

    it('gives every active reason without a filter', function () {
        $response = ($this->list)()->assertOk();

        expect($response->json('data'))->toHaveCount(17)
            ->and(array_values(array_unique(array_column($response->json('data'), 'type'))))->toBe(['ADJUSTMENT', 'CANCELLATION', 'REJECTION', 'SHIPPING_GAP']);
        // Un type vide équivaut à aucun filtre.
        ($this->list)('?type=')->assertOk()->assertJsonCount(17, 'data');
    });

    it('leaves out a deactivated reason, and orders by label when the order is the same', function () {
        ReasonCode::query()->where('type', 'SHIPPING_GAP')->where('code', 'CASSE')->update(['is_active' => false]);
        ReasonCode::query()->where('type', 'SHIPPING_GAP')->update(['sort_order' => 10]);
        ReasonCode::factory()->type(ReasonType::ShippingGap)->create(['code' => 'ABSENT', 'label' => 'Absent du quai', 'sort_order' => 10]);
        app(ReasonCodes::class)->flush();

        expect(($this->codes)(($this->list)('?type=SHIPPING_GAP')->assertOk()))->toBe([
            'SHIPPING_GAP.ABSENT', 'SHIPPING_GAP.AUTRE', 'SHIPPING_GAP.ERREUR_PREPARATION', 'SHIPPING_GAP.RUPTURE',
        ]);
    });

    it('answers 422 to an unknown type or a malformed list', function (string $query) {
        $response = ($this->list)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['type']]);
    })->with([
        'type inconnu' => '?type=PICKING',
        'un type inconnu dans la liste' => '?type=ADJUSTMENT,PICKING',
        'élément vide' => '?type=ADJUSTMENT,',
        'imbriqué' => '?type[0][]=ADJUSTMENT',
    ]);

    it('reads the reasons in a single query', function () {
        ($this->list)('?type=ADJUSTMENT'); // Réchauffe les caches (permissions).
        app()->forgetScopedInstances(); // Une requête HTTP repart de zéro.
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->list)('?type=ADJUSTMENT,REJECTION,SHIPPING_GAP,CANCELLATION')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, '"reason_codes"'));
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1);
    });
});
