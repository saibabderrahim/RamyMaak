<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\DayClosed;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Imports\Workbook;

// Désactivation d'un produit doté d'un quota publié sur la journée en cours :
// effet à la journée suivante (RG-Q07, contrat du lot 3 §4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, DayClosed::class]);
    $this->admin = admin();
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->family = ProductFamily::factory()->create();
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'family_id' => $this->family->id]);
    $this->calendar = app(DayCalendar::class);
    $this->book = app(QuotaBook::class);
    $this->today = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->url = "/api/v1/products/{$this->orange->id}";
    $this->publish = function ($day, Product $product, int $quantity): void {
        $this->book->saveDrafts($day, [['region_id' => $this->centre->id, 'product_id' => $product->id, 'quantity' => $quantity]], $this->adv);
        $this->book->publish($day, [$this->centre->id], $this->adv);
    };
    $this->entries = fn (Product $product) => AuditLog::query()
        ->where('log_name', 'referentials')
        ->where('subject_type', 'product')
        ->where('subject_id', $product->id)
        ->orderBy('id')
        ->get();
});

it('defers the deactivation of a product with a published quota today to the next day', function () {
    ($this->publish)($this->today, $this->orange, 500);
    $this->actingAs($this->admin);

    $this->patchJson($this->url, ['is_active' => false, 'name' => 'Jus orange pressée 1 L'])
        ->assertOk()
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('pending_deactivation_on', '2026-10-09')
        // Les autres champs sont modifiés tout de suite.
        ->assertJsonPath('name', 'Jus orange pressée 1 L');

    $product = $this->orange->fresh();
    expect($product->is_active)->toBeTrue()
        ->and($product->deactivate_on->format('Y-m-d'))->toBe('2026-10-09');

    $this->getJson($this->url)->assertJsonPath('pending_deactivation_on', '2026-10-09');
    $this->getJson('/api/v1/products?filter[is_active]=1')->assertJsonPath('data.0.pending_deactivation_on', '2026-10-09');

    $scheduled = ($this->entries)($this->orange)->firstWhere('event', 'deactivation.scheduled');
    expect($scheduled->description)->toBe('Désactivation du produit programmée (quota publié sur la journée en cours)')
        ->and($scheduled->causer_id)->toBe($this->admin->id)
        ->and($scheduled->properties['old'])->toBe(['deactivate_on' => null])
        ->and($scheduled->properties['attributes'])->toBe(['deactivate_on' => '2026-10-09'])
        // Le statut ne change pas encore : l'entrée « Produit modifié » ne porte que la désignation.
        ->and(($this->entries)($this->orange)->firstWhere('event', 'updated')->properties['attributes'])->not->toHaveKey('is_active');
});

it('keeps a single scheduled deactivation when it is asked again', function () {
    ($this->publish)($this->today, $this->orange, 500);
    $this->actingAs($this->admin);

    $this->patchJson($this->url, ['is_active' => false])->assertOk();
    $this->patchJson($this->url, ['is_active' => false])->assertOk()->assertJsonPath('pending_deactivation_on', '2026-10-09');
    // Une modification qui ne touche pas au statut garde la désactivation programmée.
    $this->patchJson($this->url, ['sort_order' => 3])->assertOk()->assertJsonPath('pending_deactivation_on', '2026-10-09');

    expect(($this->entries)($this->orange)->where('event', 'deactivation.scheduled'))->toHaveCount(1);
});

it('cancels a scheduled deactivation when the product is reactivated', function () {
    ($this->publish)($this->today, $this->orange, 500);
    $this->actingAs($this->admin);
    $this->patchJson($this->url, ['is_active' => false])->assertOk();

    $this->patchJson($this->url, ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('pending_deactivation_on', null);

    expect($this->orange->fresh()->deactivate_on)->toBeNull();
    $cancelled = ($this->entries)($this->orange)->firstWhere('event', 'deactivation.cancelled');
    expect($cancelled->description)->toBe('Désactivation programmée du produit annulée')
        ->and($cancelled->properties['old'])->toBe(['deactivate_on' => '2026-10-09'])
        ->and($cancelled->properties['attributes'])->toBe(['deactivate_on' => null]);
});

it('deactivates at once a product without any published quota above 0 on the open day of today', function (Closure $arrange) {
    $arrange->call($this);
    $this->actingAs($this->admin);

    $this->patchJson($this->url, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('pending_deactivation_on', null);

    expect($this->orange->fresh()->only(['is_active', 'deactivate_on']))->toBe(['is_active' => false, 'deactivate_on' => null])
        ->and(($this->entries)($this->orange)->where('event', 'deactivation.scheduled'))->toBeEmpty();
})->with([
    'no quota' => [function () {}],
    'a draft only' => [function () {
        $this->book->saveDrafts($this->today, [['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500]], $this->adv);
    }],
    'a published quota of 0' => [function () {
        $pomme = Product::factory()->create(['family_id' => $this->family->id]);
        ($this->publish)($this->today, $pomme, 100);
        ($this->publish)($this->today, $this->orange, 300);
        ($this->publish)($this->today, $this->orange, 0);
    }],
    'a quota published tomorrow' => [function () {
        ($this->publish)($this->calendar->open(BusinessClock::parseDay('2026-10-09'), $this->adv), $this->orange, 500);
    }],
    'a quota published on a closed day' => [function () {
        ($this->publish)($this->today, $this->orange, 500);
        $this->calendar->close($this->today);
    }],
    'a quota published yesterday' => [function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00', 'Africa/Algiers'));
        ($this->publish)($this->calendar->open(BusinessClock::parseDay('2026-10-07'), $this->adv), $this->orange, 500);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:30', 'Africa/Algiers'));
    }],
]);

it('exposes no pending deactivation on an ordinary product', function () {
    $this->actingAs($this->admin);

    $this->getJson($this->url)->assertOk()->assertJsonPath('pending_deactivation_on', null);
});

it('applies the scheduled deactivations once due, and journals them (ramymaak:apply-product-deactivations)', function () {
    ($this->publish)($this->today, $this->orange, 500);
    $pomme = Product::factory()->create(['family_id' => $this->family->id]);
    ($this->publish)($this->today, $pomme, 200);
    $this->actingAs($this->admin);
    $this->patchJson($this->url, ['is_active' => false])->assertOk();
    $this->patchJson("/api/v1/products/{$pomme->id}", ['is_active' => false])->assertOk();
    // Réactivée avant l'échéance : rien à appliquer.
    $this->patchJson("/api/v1/products/{$pomme->id}", ['is_active' => true])->assertOk();
    // Le planificateur n'a pas d'utilisateur connecté.
    forgetGuards();

    // Pas avant l'échéance.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:59', 'Africa/Algiers'));
    $this->artisan('ramymaak:apply-product-deactivations')
        ->expectsOutputToContain('0 désactivation(s) de produit appliquée(s).')
        ->assertSuccessful();
    expect($this->orange->fresh()->is_active)->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-09 00:01', 'Africa/Algiers'));
    $this->artisan('ramymaak:apply-product-deactivations')
        ->expectsOutputToContain('1 désactivation(s) de produit appliquée(s).')
        ->assertSuccessful();

    expect($this->orange->fresh()->only(['is_active', 'deactivate_on']))->toBe(['is_active' => false, 'deactivate_on' => null])
        ->and($pomme->fresh()->is_active)->toBeTrue();
    $applied = ($this->entries)($this->orange)->where('event', 'deactivation.applied');
    expect($applied)->toHaveCount(1)
        ->and($applied->first()->description)->toBe('Désactivation programmée du produit appliquée')
        ->and($applied->first()->causer_id)->toBeNull()
        ->and($applied->first()->properties['old'])->toBe(['is_active' => true, 'deactivate_on' => '2026-10-09'])
        ->and($applied->first()->properties['attributes'])->toBe(['is_active' => false, 'deactivate_on' => null]);

    // Rejouable sans double effet.
    $this->artisan('ramymaak:apply-product-deactivations')
        ->expectsOutputToContain('0 désactivation(s) de produit appliquée(s).')
        ->assertSuccessful();
    expect(($this->entries)($this->orange)->where('event', 'deactivation.applied'))->toHaveCount(1);
});

it('catches up on overdue deactivations and clears a stale date on an inactive product', function () {
    $late = Product::factory()->create(['family_id' => $this->family->id]);
    $late->forceFill(['deactivate_on' => '2026-10-05'])->save();
    $stale = Product::factory()->inactive()->create(['family_id' => $this->family->id]);
    $stale->forceFill(['deactivate_on' => '2026-10-06'])->save();
    $future = Product::factory()->create(['family_id' => $this->family->id]);
    $future->forceFill(['deactivate_on' => '2026-10-09'])->save();

    $this->artisan('ramymaak:apply-product-deactivations')
        ->expectsOutputToContain('1 désactivation(s) de produit appliquée(s).')
        ->assertSuccessful();

    expect($late->fresh()->only(['is_active', 'deactivate_on']))->toBe(['is_active' => false, 'deactivate_on' => null])
        ->and($stale->fresh()->only(['is_active', 'deactivate_on']))->toBe(['is_active' => false, 'deactivate_on' => null])
        ->and($future->fresh()->is_active)->toBeTrue()
        ->and($future->fresh()->deactivate_on->format('Y-m-d'))->toBe('2026-10-09')
        ->and(($this->entries)($stale)->where('event', 'deactivation.applied'))->toBeEmpty();
});

it('is scheduled at 00:01, Algiers time, without overlapping', function () {
    app(Kernel::class)->bootstrap();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'ramymaak:apply-product-deactivations'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('1 0 * * *')
        ->and($events->first()->timezone)->toBe('Africa/Algiers')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('defers a deactivation imported from Excel too, and leaves a re-imported ACTIF unchanged (RG-Q07, §8.3)', function () {
    Storage::fake('local');
    ($this->publish)($this->today, $this->orange, 500);
    $other = Product::factory()->create(['sku' => 'P0057', 'family_id' => $this->family->id]);
    $this->actingAs($this->admin);

    // En-têtes de l'annexe B.4 ; une cellule vide laisse la valeur inchangée.
    $header = ['code_produit', 'designation', 'designation_courte', 'code_famille', 'format', 'libelle_uv',
        'unites_par_uv', 'uv_par_palette', 'ean', 'taux_tva', 'statut', 'ordre_affichage'];
    $import = function (array $rows) use ($header) {
        $rows = array_map(fn (array $row) => [$row[0], null, null, null, null, null, null, null, null, null, $row[1], null], $rows);
        $id = $this->post('/api/v1/imports', ['type' => 'PRODUCTS', 'file' => Workbook::upload([$header, ...$rows], 'produits.xlsx')])
            ->assertStatus(202)->json('id');
        $this->getJson("/api/v1/imports/{$id}")->assertJsonPath('status', 'READY');
        $this->postJson("/api/v1/imports/{$id}/apply")->assertOk()->assertJsonPath('status', 'APPLIED');
    };

    $import([['P0042', 'INACTIF'], ['P0057', 'INACTIF']]);
    $orange = $this->orange->fresh();
    expect($orange->is_active)->toBeTrue()
        ->and($orange->deactivate_on->format('Y-m-d'))->toBe('2026-10-09')
        // Sans quota publié aujourd'hui : désactivation immédiate.
        ->and($other->fresh()->is_active)->toBeFalse()
        ->and($other->fresh()->deactivate_on)->toBeNull()
        ->and(($this->entries)($this->orange)->pluck('event')->all())->toContain('deactivation.scheduled');

    // « ACTIF » réimporté sur un produit encore actif : la désactivation programmée reste.
    $import([['P0042', 'ACTIF']]);
    expect($this->orange->fresh()->deactivate_on?->format('Y-m-d'))->toBe('2026-10-09');
});
