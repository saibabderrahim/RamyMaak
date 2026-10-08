<?php

namespace Database\Seeders;

use App\Domain\Referentials\AssignmentKind;
use App\Domain\Referentials\ClientRegistry;
use App\Domain\Referentials\ClientStatus;
use App\Domain\Referentials\PriceBook;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Depot;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Database\Seeder;

/**
 * Référentiels de démonstration (développement et recette) : dépôts,
 * régions, catalogue avec prix, clients ; rattache les comptes de
 * démonstration « regional » (titulaire de CENTRE) et « c0012 ».
 * Rejouable.
 */
class DemoReferentialsSeeder extends Seeder
{
    public function run(ClientRegistry $clients, PriceBook $prices): void
    {
        $blida = Depot::query()->updateOrCreate(['code' => 'BLIDA'], ['name' => 'Dépôt Blida', 'address' => 'Zone industrielle, Blida']);
        $oran = Depot::query()->updateOrCreate(['code' => 'ORAN'], ['name' => 'Dépôt Oran', 'address' => 'Es Senia, Oran']);

        $regions = [];
        foreach ([
            'CENTRE' => ['Centre', $blida],
            'EST' => ['Est', $blida],
            'OUEST' => ['Ouest', $oran],
            'SUD' => ['Sud', null],
        ] as $code => [$name, $depot]) {
            $regions[$code] = Region::query()->updateOrCreate(['code' => $code], ['name' => $name, 'depot_id' => $depot?->id]);
        }

        $categories = [];
        foreach (['GROSSISTE' => 'Grossiste', 'DISTRIBUTEUR' => 'Distributeur', 'DETAILLANT' => 'Détaillant'] as $code => $name) {
            $categories[$code] = ClientCategory::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $families = [];
        foreach (['JUS' => ['Jus', 10], 'LAIT' => ['Lait', 20], 'LBEN' => ['Lben et raïb', 30], 'GAZ' => ['Boissons gazeuses', 40]] as $code => [$name, $order]) {
            $families[$code] = ProductFamily::query()->updateOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $order]);
        }

        $today = BusinessClock::today();
        $firstOfMonth = $today->startOfMonth();
        foreach ([
            ['P0042', 'Jus orange 1 L', 'Orange 1 L', 'JUS', '1 L', 'Fardeau 6 × 1 L', 6, 80, '900', '950'],
            ['P0057', 'Jus pomme 1 L', 'Pomme 1 L', 'JUS', '1 L', 'Fardeau 6 × 1 L', 6, 80, '900', null],
            ['P0061', 'Jus mangue 1 L', 'Mangue 1 L', 'JUS', '1 L', 'Fardeau 6 × 1 L', 6, 80, '960', null],
            ['P0075', 'Jus cocktail 25 cl', 'Cocktail 25 cl', 'JUS', '25 cl', 'Pack 24 × 25 cl', 24, 90, '1150', null],
            ['P0103', 'Lben 1 L', 'Lben 1 L', 'LBEN', '1 L', 'Fardeau 6 × 1 L', 6, 100, '480', null],
            ['P0110', 'Raïb 1 L', 'Raïb 1 L', 'LBEN', '1 L', 'Fardeau 6 × 1 L', 6, 100, '510', null],
            ['P0201', 'Lait demi-écrémé 1 L', 'Lait 1 L', 'LAIT', '1 L', 'Fardeau 6 × 1 L', 6, 100, '540', '560'],
            ['P0305', 'Ramy Up orange 2 L', 'Up orange 2 L', 'GAZ', '2 L', 'Fardeau 6 × 2 L', 6, 60, '780', null],
        ] as $index => [$sku, $name, $short, $family, $format, $unit, $units, $perPallet, $price, $future]) {
            $product = Product::query()->updateOrCreate(['sku' => $sku], [
                'name' => $name,
                'short_name' => $short,
                'family_id' => $families[$family]->id,
                'format' => $format,
                'sales_unit_label' => $unit,
                'units_per_sales_unit' => $units,
                'sales_units_per_pallet' => $perPallet,
                'vat_rate' => '19.00',
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
            ]);
            $prices->setPrice($product, $price, $firstOfMonth, null);
            if ($future !== null) {
                $prices->setPrice($product, $future, $today->addMonthNoOverflow()->startOfMonth(), null);
            }
        }

        foreach ([
            ['C0012', 'Superette El Baraka', 'El Baraka', 'DETAILLANT', 'CENTRE', 'Alger', 'Bab Ezzouar', '0550123456', null],
            ['C0047', 'Grossiste Amine', null, 'GROSSISTE', 'CENTRE', 'Blida', 'Boufarik', '0661234567', null],
            ['C0058', 'Distribution Mitidja', 'Mitidja Distri', 'DISTRIBUTEUR', 'CENTRE', 'Blida', 'Larbaâ', '0770345678', null],
            ['C0063', 'Alimentation Générale Nour', 'Nour', 'DETAILLANT', 'CENTRE', 'Alger', 'Kouba', '0552456789', 'Impayés'],
            ['C0101', 'Grossiste Cirta', null, 'GROSSISTE', 'EST', 'Constantine', 'El Khroub', '0663567890', null],
            ['C0118', 'Sétif Boissons', null, 'DISTRIBUTEUR', 'EST', 'Sétif', 'El Eulma', '0771678901', null],
            ['C0204', 'Comptoir Oranais', null, 'GROSSISTE', 'OUEST', 'Oran', 'Es Senia', '0554789012', null],
            ['C0215', 'Superette Tlemcen Centre', null, 'DETAILLANT', 'OUEST', 'Tlemcen', 'Mansourah', '0665890123', null],
            ['C0301', 'Distribution Ghardaïa', null, 'DISTRIBUTEUR', 'SUD', 'Ghardaïa', 'Metlili', '0772901234', null],
        ] as [$code, $name, $tradeName, $category, $region, $wilaya, $commune, $phone, $blockedReason]) {
            $attributes = [
                'code' => $code,
                'name' => $name,
                'trade_name' => $tradeName,
                'category_id' => $categories[$category]->id,
                'region_id' => $regions[$region]->id,
                'wilaya' => $wilaya,
                'commune' => $commune,
                'phone' => $phone,
            ];
            $client = Client::query()->where('code', $code)->first();
            if ($client === null) {
                $client = $clients->create($attributes, null);
            }
            if ($blockedReason !== null) {
                $clients->changeStatus($client, ClientStatus::Blocked, $blockedReason, null);
            }
        }

        if ($regional = User::query()->where('username', 'regional')->first()) {
            RegionAssignment::query()->firstOrCreate(
                ['region_id' => $regions['CENTRE']->id, 'user_id' => $regional->id, 'kind' => AssignmentKind::Titular->value, 'revoked_at' => null],
                ['starts_on' => $firstOfMonth->format('Y-m-d')],
            );
        }

        User::query()->where('username', 'c0012')->update([
            'client_id' => Client::query()->where('code', 'C0012')->value('id'),
        ]);
    }
}
