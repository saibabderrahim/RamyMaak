<?php

use App\Domain\Access\Role;
use App\Domain\Referentials\AssignmentKind;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Depot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Données communes des tests des documents (lot 6, volet C) : une journée,
 * des régions avec et sans dépôt, des produits de deux familles (avec et sans
 * colisage) et des clients. Les commandes se créent avec documentsOrder(),
 * sans passer par les services (les documents ne lisent que la base).
 *
 * Les noms de fonctions sont uniques dans tests/ (fonctions globales).
 */
function documentsWorld(object $test): void
{
    $test->day = BusinessDay::factory()->on('2026-10-08')->create(['delivery_date' => '2026-10-09']);

    $test->depot = Depot::factory()->create(['code' => 'BLIDA', 'name' => 'Dépôt de Blida']);
    $test->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre', 'depot_id' => $test->depot->id]);
    $test->ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest', 'depot_id' => $test->depot->id]);
    $test->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);

    $boissons = ProductFamily::factory()->create(['code' => 'BOI', 'name' => 'Boissons', 'sort_order' => 1]);
    $laitiers = ProductFamily::factory()->create(['code' => 'LAI', 'name' => 'Laitiers', 'sort_order' => 2]);
    $test->orange = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus Orange 1 L', 'family_id' => $boissons->id, 'sort_order' => 10,
        'sales_unit_label' => 'Fardeau 6 × 1 L', 'sales_units_per_pallet' => 48, 'vat_rate' => '19.00',
    ]);
    $test->pomme = Product::factory()->create([
        'sku' => 'P0057', 'name' => 'Jus Pomme 1 L', 'family_id' => $boissons->id, 'sort_order' => 20,
        'sales_unit_label' => 'Fardeau 6 × 1 L', 'sales_units_per_pallet' => null, 'vat_rate' => '9.00',
    ]);
    $test->lben = Product::factory()->create([
        'sku' => 'P0100', 'name' => 'Lben 1 L', 'family_id' => $laitiers->id, 'sort_order' => 5,
        'sales_unit_label' => 'Carton 12 × 1 L', 'sales_units_per_pallet' => 60, 'vat_rate' => '9.00',
    ]);

    $test->a = Client::factory()->create([
        'code' => 'C0012', 'name' => 'Épicerie El Baraka', 'region_id' => $test->centre->id, 'nif' => '001234567890123', 'rc' => '16/00-1234567B21',
        'address' => '12 rue Didouche Mourad', 'commune' => 'Alger-Centre', 'wilaya' => 'Alger', 'phone' => '0550123456',
    ]);
    $test->b = Client::factory()->create(['code' => 'C0047', 'name' => 'Supérette Ibn Sina', 'region_id' => $test->centre->id]);
    $test->o = Client::factory()->create(['code' => 'C0200', 'name' => 'Marché d\'Oran', 'region_id' => $test->ouest->id]);
    $test->e = Client::factory()->create(['code' => 'C0300', 'name' => 'Grossiste de l\'Est', 'region_id' => $test->est->id]);

    $test->regional = User::factory()->role(Role::Regional)->create(['name' => 'Karim Régional']);
    RegionAssignment::factory()->create(['region_id' => $test->centre->id, 'user_id' => $test->regional->id, 'kind' => AssignmentKind::Titular, 'starts_on' => '2026-10-01']);
    $test->adv = User::factory()->role(Role::Adv)->create();
    $test->admin = User::factory()->role(Role::Admin)->create();
    $test->clientUser = User::factory()->role(Role::Client)->create(['client_id' => $test->a->id]);
}

/**
 * Commande de la journée du test.
 *
 * `$lines` : [[Product, demandé, validé], …] (prix 900,00 DA HT, TVA du produit) ; `$state` : validated,
 * inPreparation, prepared, shipped, rejected, cancelled, expired ou submitted.
 *
 * @param  list<array{0: Product, 1: int, 2?: ?int}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function documentsOrder(object $test, Client $client, string $number, string $state, array $lines, array $attributes = []): Order
{
    $factory = Order::factory()->state([
        'number' => $number,
        'business_day_id' => $test->day->id,
        'client_id' => $client->id,
        'region_id' => $client->region_id,
        'submitted_at' => '2026-10-08 07:15:00',
    ]);
    $factory = match ($state) {
        'validated', 'inPreparation', 'prepared', 'shipped', 'rejected', 'cancelled', 'expired' => $factory->{$state}(),
        'submitted' => $factory,
    };
    if (in_array($state, ['validated', 'inPreparation', 'prepared', 'shipped'], true)) {
        $factory = $factory->state(['decided_at' => '2026-10-08 11:45:00', 'decided_by' => $test->regional->id]);
    }

    return $factory->withLines($lines)->create($attributes);
}

/** Corps d'une réponse, flux compris (la sortie du flux est capturée, jamais envoyée). */
function documentsBody(Response $response): string
{
    if ($response instanceof StreamedResponse) {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    return (string) $response->getContent();
}

/**
 * Lignes d'une feuille (la première par défaut) d'un fichier XLSX, relu avec
 * le lecteur d'OpenSpout : liste de lignes, chacune liste de valeurs de cellules.
 *
 * @return list<list<mixed>>
 */
function documentsSheetRows(string $binary, int $sheetIndex = 0): array
{
    $path = tempnam(sys_get_temp_dir(), 'docs-xlsx-');
    file_put_contents($path, $binary);
    $options = new Options;
    $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
    $reader = new Reader($options);
    $reader->open($path);
    $rows = [];
    try {
        foreach ($reader->getSheetIterator() as $index => $sheet) {
            if ($index - 1 !== $sheetIndex) {
                continue;
            }
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }
    } finally {
        $reader->close();
        unlink($path);
    }

    return $rows;
}

/**
 * Texte d'un document HTML : en-tête retiré, balises remplacées par une
 * espace, entités décodées, blancs ASCII regroupés (les espaces insécables
 * des montants sont conservées).
 */
function documentsText(string $html): string
{
    $body = (string) preg_replace(['#<head>.*?</head>#s', '#<[^>]+>#'], ['', ' '], $html);

    return trim((string) preg_replace('/[ \t\r\n]+/', ' ', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/**
 * Lignes d'un CSV (point-virgule), BOM retiré.
 *
 * @return list<list<string|null>>
 */
function documentsCsvRows(string $csv): array
{
    $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;

    return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", $csv), fn (string $line) => $line !== '')));
}
