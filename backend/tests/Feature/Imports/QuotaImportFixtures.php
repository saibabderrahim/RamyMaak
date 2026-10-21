<?php

namespace Tests\Feature\Imports;

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Jeu de données des imports de quotas (§8.1, Annexe B.1), le jeudi
 * 08/10/2026 à 07:30 (heure d'Alger) :
 *
 * - régions actives CENTRE, EST, OUEST ; SUD inactive ;
 * - familles JUS (ordre 1) et LAIT (ordre 2) ; produits actifs P0042 et P0057
 *   (JUS), P0103 (LAIT), P0999 inactif ;
 * - journée du 08/10 ouverte par l'ADV ; quotas publiés CENTRE P0042 = 500,
 *   CENTRE P0057 = 300, EST P0042 = 200, puis brouillon CENTRE P0042 = 450 ;
 *   OUEST sans quota (QUOTA_DRAFT).
 *
 * Valeurs actuelles (brouillon, sinon publié) : CENTRE P0042 450, P0057 300 ;
 * EST P0042 200 ; le reste 0. Les événements de domaine sont simulés.
 */
final class QuotaImportFixtures
{
    public const DAY = '2026-10-08';

    /** En-tête du gabarit pour les trois régions actives. */
    public const HEADER = ['code_produit', 'designation (information)', 'CENTRE', 'EST', 'OUEST'];

    public static function install(TestCase $test): void
    {
        Storage::fake('local');
        $test->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
        Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, DayClosed::class, CutoffExtended::class]);

        $test->calendar = app(DayCalendar::class);
        $test->book = app(QuotaBook::class);
        $test->adv = userWithRole(Role::Adv, ['username' => 'a.adv']);

        $test->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
        $test->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
        $test->ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest']);
        $test->sud = Region::factory()->create(['code' => 'SUD', 'name' => 'Sud', 'is_active' => false]);

        $jus = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 1]);
        $lait = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 2]);
        $test->lben = Product::factory()->create(['sku' => 'P0103', 'name' => 'Lben 1 L', 'family_id' => $lait->id, 'sort_order' => 1]);
        $test->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'family_id' => $jus->id, 'sort_order' => 20]);
        $test->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'family_id' => $jus->id, 'sort_order' => 10]);
        $test->retired = Product::factory()->inactive()->create(['sku' => 'P0999', 'name' => 'Jus retiré', 'family_id' => $jus->id]);

        $test->day = $test->calendar->open(BusinessClock::parseDay(self::DAY), $test->adv);
        $test->book->saveDrafts($test->day, [
            ['region_id' => $test->centre->id, 'product_id' => $test->orange->id, 'quantity' => 500],
            ['region_id' => $test->centre->id, 'product_id' => $test->pomme->id, 'quantity' => 300],
            ['region_id' => $test->est->id, 'product_id' => $test->orange->id, 'quantity' => 200],
        ], $test->adv);
        $test->book->publish($test->day, [$test->centre->id, $test->est->id], $test->adv);
        $test->book->saveDrafts($test->day, [
            ['region_id' => $test->centre->id, 'product_id' => $test->orange->id, 'quantity' => 450],
        ], $test->adv);

        // Le jeu de données ne compte pas dans les assertions des tests.
        Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, DayClosed::class, CutoffExtended::class]);

        $test->upload = fn (array $rows, string $mode = 'REPLACE', ?User $user = null, string $day = self::DAY) => $test
            ->actingAs($user ?? $test->adv)
            ->post('/api/v1/imports', [
                'type' => 'QUOTAS',
                'business_day' => $day,
                'mode' => $mode,
                'file' => Workbook::upload($rows, 'quotas.xlsx'),
            ]);

        $test->report = fn (int $id) => $test->getJson("/api/v1/imports/{$id}")->assertOk()->json();

        $test->quota = fn (Region $region, Product $product) => RegionQuota::query()
            ->where('product_id', $product->id)
            ->whereHas('regionDay', fn ($query) => $query->where('business_day_id', $test->day->id)->where('region_id', $region->id))
            ->first();

        $test->regionDay = fn (Region $region) => RegionDay::query()
            ->where('business_day_id', $test->day->id)
            ->where('region_id', $region->id)
            ->sole();

        // Compteur validé V(r,p) : jamais affecté en masse (§5.8).
        $test->validate = fn (Region $region, Product $product, int $quantity) => RegionQuota::query()
            ->whereKey(($test->quota)($region, $product)->id)
            ->update(['validated_qty' => $quantity]);
    }

    /**
     * État de toutes les cellules de la journée : « RÉGION|SKU » → [Q, brouillon, V].
     *
     * @return array<string, array{0: int, 1: ?int, 2: int}>
     */
    public static function cells(TestCase $test): array
    {
        return RegionQuota::query()
            ->with(['regionDay.region', 'product'])
            ->whereHas('regionDay', fn ($query) => $query->where('business_day_id', $test->day->id))
            ->get()
            ->mapWithKeys(fn (RegionQuota $quota) => [
                $quota->regionDay->region->code.'|'.$quota->product->sku => [$quota->quantity, $quota->draft_quantity, $quota->validated_qty],
            ])
            ->sortKeys()
            ->all();
    }
}
