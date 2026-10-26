<?php

use App\Domain\Documents\ErpExportLayout;
use App\Domain\Documents\ExportFormat;
use App\Domain\Documents\OrderExport;
use App\Domain\Reasons\ReasonCodes;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Region;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/DocumentsWorld.php';

// Export des commandes de la journée (RG-A04, EF-ADV-07, I-83, I-85) : une
// ligne par ligne de commande, XLSX, CSV pour Excel français et format ERP
// provisoire erp-v0 ; neutralisation des formules, plafond de lignes, mémoire.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    documentsWorld($this);

    // Validée (ajustée), en préparation, préparée, expédiée avec un écart, puis les statuts hors ERP.
    documentsOrder($this, $this->a, 'RMK-261008-00001', 'validated', [[$this->orange, 40, 40], [$this->pomme, 15, 10]], ['is_adjusted' => true]);
    OrderLine::query()->where('product_id', $this->pomme->id)->update(['adjustment_reason_code' => 'QUOTA_INSUFFISANT']);
    documentsOrder($this, $this->b, 'RMK-261008-00002', 'inPreparation', [[$this->lben, 20, 20]]);
    documentsOrder($this, $this->o, 'RMK-261008-00003', 'prepared', [[$this->orange, 25, 25], [$this->pomme, 5, 0]]);
    $shipped = documentsOrder($this, $this->e, 'RMK-261008-00004', 'shipped', [[$this->lben, 100, 100]], ['delivery_note_ref' => 'BL-0001', 'shipped_at' => '2026-10-08 14:00:00']);
    $shipped->lines()->update(['shipped_qty' => 98, 'shipping_gap_reason_code' => 'RUPTURE']);
    documentsOrder($this, $this->a, 'RMK-261008-00005', 'rejected', [[$this->orange, 99, null]], ['decided_by' => $this->regional->id]);
    documentsOrder($this, $this->a, 'RMK-261008-00006', 'cancelled', [[$this->orange, 98, 98]]);
    documentsOrder($this, $this->a, 'RMK-261008-00007', 'expired', [[$this->orange, 97, null]]);
    documentsOrder($this, $this->a, 'RMK-261008-00008', 'submitted', [[$this->orange, 96, null]]);

    $this->url = '/api/v1/adv/days/2026-10-08/orders/export';
    $this->download = fn (string $query = '') => $this->actingAs($this->adv)->get($this->url.($query === '' ? '' : '?'.$query));
    $this->rows = function (string $query) {
        $response = ($this->download)($query);
        $response->assertOk();
        $body = $response->streamedContent();

        return str_contains($query, 'format=xlsx') || $query === '' ? documentsSheetRows($body) : documentsCsvRows($body);
    };
});

describe('XLSX', function () {
    it('writes one row per order line, every status by default, under the French headers', function () {
        $rows = ($this->rows)('');

        expect($rows[0])->toBe([
            'Journée', 'Livraison', 'Numéro', 'Statut', 'Ajustée', 'Code client', 'Client', 'Région', 'Dépôt', 'SKU', 'Produit', 'UV',
            'Demandé', 'Validé', 'Expédié', 'Motif ajustement', 'Motif écart', 'Prix HT', 'TVA %', 'Montant HT validé',
            'Envoyée le', 'Décidée le', 'Décidée par', 'Expédiée le', 'BL',
        ])->and($rows)->toHaveCount(1 + 10)
            // Tous les statuts : validée à expirée, et la commande envoyée.
            ->and(array_values(array_unique(array_column(array_slice($rows, 1), 3))))->toEqualCanonicalizing(['Validée', 'En préparation', 'Préparée', 'Expédiée', 'Rejetée', 'Annulée', 'Expirée', 'Envoyée']);
    });

    it('fills an adjusted validated line', function () {
        $rows = ($this->rows)('format=xlsx');

        expect($rows[1])->toEqual([
            '08/10/2026', '09/10/2026', 'RMK-261008-00001', 'Validée', 'Oui', 'C0012', 'Épicerie El Baraka', 'Centre', 'Dépôt de Blida', 'P0042',
            'Jus Orange 1 L', 'Fardeau 6 × 1 L', 40, 40, '', '', '', 900, 19, 36000, '08/10/2026 08:15', '08/10/2026 12:45', 'Karim Régional', '', '',
        ])->and($rows[2])->toEqual([
            '08/10/2026', '09/10/2026', 'RMK-261008-00001', 'Validée', 'Oui', 'C0012', 'Épicerie El Baraka', 'Centre', 'Dépôt de Blida', 'P0057',
            'Jus Pomme 1 L', 'Fardeau 6 × 1 L', 15, 10, '', 'Quota insuffisant', '', 900, 9, 9000, '08/10/2026 08:15', '08/10/2026 12:45', 'Karim Régional', '', '',
        ]);
    });

    it('fills a shipped line with its gap, shipping time and delivery note, without depot', function () {
        $rows = ($this->rows)('format=xlsx');
        $shipped = array_values(array_filter($rows, fn (array $row) => $row[2] === 'RMK-261008-00004'))[0];

        expect($shipped)->toEqual([
            '08/10/2026', '09/10/2026', 'RMK-261008-00004', 'Expédiée', 'Non', 'C0300', 'Grossiste de l\'Est', 'Est', '', 'P0100',
            'Lben 1 L', 'Carton 12 × 1 L', 100, 100, 98, '', 'Rupture de stock', 900, 9, 90000, '08/10/2026 08:15', '08/10/2026 12:45', 'Karim Régional',
            '08/10/2026 15:00', 'BL-0001',
        ]);
    });

    it('leaves the quantities and amounts of undecided orders empty and keeps a validated 0', function () {
        $rows = ($this->rows)('format=xlsx');
        $byNumber = fn (string $number): array => array_values(array_filter($rows, fn (array $row) => $row[2] === $number));

        // Commande envoyée : rien de validé, rien d'expédié, pas de montant.
        $submitted = $byNumber('RMK-261008-00008')[0];
        expect([$submitted[3], $submitted[12], $submitted[13], $submitted[14], $submitted[19], $submitted[21], $submitted[22]])->toBe(['Envoyée', 96, '', '', '', '', ''])
            // Ligne validée à 0 : 0 et un montant nul, pas une cellule vide.
            ->and([$byNumber('RMK-261008-00003')[1][13], $byNumber('RMK-261008-00003')[1][19]])->toBe([0, 0]);
    });

    it('writes numeric cells, a frozen header and an automatic filter', function () {
        $rows = ($this->rows)('format=xlsx');
        $path = tempnam(sys_get_temp_dir(), 'docs-xlsx-');
        file_put_contents($path, ($this->download)('format=xlsx')->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        // Quantités et montants numériques, jamais du texte.
        expect($rows[1][12])->toBeInt()->and($rows[1][17])->toBeNumeric()->and($rows[1][17])->not->toBeString()->and($rows[1][19])->not->toBeString()
            ->and($sheet)->toContain('<autoFilter ref="A1:Y11"/>')->toContain('state="frozen"')
            ->toMatch('#<c r="M2"[^>]*><v>40</v></c>#')->toMatch('#<c r="T2"[^>]*><v>36000</v></c>#');
    });

    it('carries the indicative amounts notice on a second sheet, keeping the table flat', function () {
        $body = ($this->download)('format=xlsx')->streamedContent();
        $info = documentsSheetRows($body, 1);

        expect($info[0])->toBe(['Export des commandes de la journée'])
            ->and($info[1])->toBe(['Montants indicatifs : la facture ERP fait foi.'])
            ->and($info[2][0])->toBe('Généré le 08/10/2026 09:30')
            ->and(documentsSheetRows($body))->toHaveCount(1 + 10);
    });

    it('is an XLSX attachment named after the day, never cached', function () {
        $response = ($this->download)('format=xlsx');

        expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('commandes-2026-10-08.xlsx')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('defaults to XLSX', function () {
        expect(($this->download)()->headers->get('Content-Disposition'))->toContain('commandes-2026-10-08.xlsx');
    });
});

describe('CSV for French Excel', function () {
    it('starts with a UTF-8 BOM and uses semicolons', function () {
        $raw = ($this->download)('format=csv')->streamedContent();

        expect(substr($raw, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and(explode("\n", $raw)[0])->toBe("\xEF\xBB\xBFJournée;Livraison;Numéro;Statut;Ajustée;\"Code client\";Client;Région;Dépôt;SKU;Produit;UV;Demandé;Validé;Expédié;\"Motif ajustement\";\"Motif écart\";\"Prix HT\";\"TVA %\";\"Montant HT validé\";\"Envoyée le\";\"Décidée le\";\"Décidée par\";\"Expédiée le\";BL");
    });

    it('ends with the indicative amounts notice after an empty line (RG-P05)', function () {
        $raw = ($this->download)('format=csv')->streamedContent();

        expect(str_ends_with($raw, "\n\n\"Montants indicatifs : la facture ERP fait foi.\"\n"))->toBeTrue();
    });

    it('writes decimal commas and French dates', function () {
        $rows = ($this->rows)('format=csv');

        expect($rows)->toHaveCount(1 + 10)
            ->and($rows[1])->toBe([
                '08/10/2026', '09/10/2026', 'RMK-261008-00001', 'Validée', 'Oui', 'C0012', 'Épicerie El Baraka', 'Centre', 'Dépôt de Blida', 'P0042',
                'Jus Orange 1 L', 'Fardeau 6 × 1 L', '40', '40', '', '', '', '900,00', '19,00', '36000,00', '08/10/2026 08:15', '08/10/2026 12:45', 'Karim Régional', '', '',
            ]);
    });

    it('is a CSV attachment, never cached', function () {
        $response = ($this->download)('format=csv');

        expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('commandes-2026-10-08.csv')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('ERP format erp-v0', function () {
    it('has no BOM, ISO dates, decimal points and only Validée to Expédiée', function () {
        $raw = ($this->download)('format=erp')->streamedContent();
        $rows = documentsCsvRows($raw);

        expect(substr($raw, 0, 3))->not->toBe("\xEF\xBB\xBF")
            ->and(explode("\n", $raw)[0])->toBe('day;delivery_date;order_number;client_code;region_code;depot_code;sku;validated_qty;shipped_qty;unit_price_ht;vat_rate;amount_ht;status')
            ->and($rows[0])->toBe(ErpExportLayout::COLUMNS)
            ->and($rows)->toHaveCount(1 + 6)
            ->and($rows[1])->toBe(['2026-10-08', '2026-10-09', 'RMK-261008-00001', 'C0012', 'CENTRE', 'BLIDA', 'P0042', '40', '', '900.00', '19.00', '36000.00', 'VALIDATED'])
            ->and($rows[2])->toBe(['2026-10-08', '2026-10-09', 'RMK-261008-00001', 'C0012', 'CENTRE', 'BLIDA', 'P0057', '10', '', '900.00', '9.00', '9000.00', 'VALIDATED'])
            // Région sans dépôt : code de dépôt vide ; quantité expédiée remplie.
            ->and(array_values(array_filter($rows, fn (array $row) => $row[2] === 'RMK-261008-00004'))[0])
            ->toBe(['2026-10-08', '2026-10-09', 'RMK-261008-00004', 'C0300', 'EST', '', 'P0100', '100', '98', '900.00', '9.00', '90000.00', 'SHIPPED']);
    });

    it('never exports a cancelled, rejected, expired or sent order', function () {
        $rows = documentsCsvRows(($this->download)('format=erp')->streamedContent());

        expect(array_values(array_unique(array_column(array_slice($rows, 1), 12))))->toEqualCanonicalizing(['VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED'])
            ->and(array_values(array_unique(array_column(array_slice($rows, 1), 2))))->not->toContain('RMK-261008-00006');
    });

    it('refuses a status filter outside Validée to Expédiée with 422', function (string $status) {
        $response = ($this->download)('format=erp&filter[status]='.$status);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['filter.status']]);
    })->with(['CANCELLED', 'VALIDATED,CANCELLED', 'REJECTED', 'EXPIRED', 'SUBMITTED']);

    it('accepts a narrower status filter', function () {
        $rows = documentsCsvRows(($this->download)('format=erp&filter[status]=shipped,prepared')->streamedContent());

        expect(array_values(array_unique(array_column(array_slice($rows, 1), 12))))->toEqualCanonicalizing(['PREPARED', 'SHIPPED']);
    });

    it('is a named CSV attachment without cache', function () {
        $response = ($this->download)('format=erp');

        expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
            ->and($response->headers->get('Content-Disposition'))->toContain('commandes-erp-v0-2026-10-08.csv')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});

it('refuses a format sent as a list with 422, never 500', function () {
    $response = $this->actingAs($this->adv)->getJson('/api/v1/adv/days/2026-10-08/orders/export?format[]=erp&filter[status]=VALIDATED');

    expectProblem($response, 422, 'VALIDATION_FAILED');
    expect($response->json('errors'))->toHaveKey('format');
});

describe('filters', function () {
    it('keeps the statuses asked for, in any case, as a list or an array', function (string $query) {
        $rows = ($this->rows)($query);

        expect(array_values(array_unique(array_column(array_slice($rows, 1), 3))))->toEqualCanonicalizing(['Annulée', 'Rejetée']);
    })->with([
        'liste' => 'format=csv&filter[status]=CANCELLED,REJECTED',
        'minuscules' => 'format=csv&filter[status]=cancelled,rejected',
        'tableau' => 'format=csv&filter[status][]=CANCELLED&filter[status][]=REJECTED',
    ]);

    it('keeps the region asked for', function () {
        $rows = ($this->rows)('format=csv&filter[region]='.$this->est->id);

        expect(array_values(array_unique(array_column(array_slice($rows, 1), 7))))->toBe(['Est'])->and($rows)->toHaveCount(2);
    });

    it('keeps the status and region filters together', function () {
        $rows = ($this->rows)('format=csv&filter[status]=VALIDATED,PREPARED&filter[region]='.$this->ouest->id);

        expect(array_column(array_slice($rows, 1), 2))->toBe(['RMK-261008-00003', 'RMK-261008-00003']);
    });

    it('exports an empty file with its header for a region without orders', function () {
        $quiet = Region::factory()->create(['code' => 'SUD']);

        $csv = ($this->rows)('format=csv&filter[region]='.$quiet->id);
        $xlsx = ($this->rows)('format=xlsx&filter[region]='.$quiet->id);

        expect($csv)->toHaveCount(1)->and($xlsx)->toHaveCount(1);
    });

    it('reads only the orders of the day asked', function () {
        BusinessDay::factory()->on('2026-10-07')->create();

        $rows = documentsCsvRows($this->actingAs($this->adv)->get('/api/v1/adv/days/2026-10-07/orders/export?format=csv')->streamedContent());

        expect($rows)->toHaveCount(1);
    });

    it('exports the orders in order of arrival, then the lines of each order', function () {
        $rows = ($this->rows)('format=csv');

        expect(array_column(array_slice($rows, 1), 2))->toBe([
            'RMK-261008-00001', 'RMK-261008-00001', 'RMK-261008-00002', 'RMK-261008-00003', 'RMK-261008-00003', 'RMK-261008-00004',
            'RMK-261008-00005', 'RMK-261008-00006', 'RMK-261008-00007', 'RMK-261008-00008',
        ]);
    });
});

describe('edge cases', function () {
    it('leaves the delivery date empty when the day has none', function () {
        $this->day->update(['delivery_date' => null]);

        $csv = ($this->rows)('format=csv');
        $xlsx = ($this->rows)('format=xlsx');
        $erp = documentsCsvRows(($this->download)('format=erp')->streamedContent());

        expect([$csv[1][1], $xlsx[1][1], $erp[1][1]])->toBe(['', '', '']);
    });

    it('keeps the label of a deactivated reason and the code of an unknown one', function () {
        ReasonCode::query()->where('code', 'QUOTA_INSUFFISANT')->where('type', 'ADJUSTMENT')->update(['is_active' => false]);
        OrderLine::query()->where('product_id', $this->orange->id)->where('requested_qty', 40)->update(['adjustment_reason_code' => 'ANCIEN_MOTIF']);

        $rows = ($this->rows)('format=csv');

        expect($rows[1][15])->toBe('ANCIEN_MOTIF')->and($rows[2][15])->toBe('Quota insuffisant');
    });

    it('quotes the cells that contain the separator or quotes in the CSV', function () {
        $this->orange->update(['name' => 'Jus "Pur"; 1 L']);

        $rows = ($this->rows)('format=csv');

        expect($rows[1][10])->toBe('Jus "Pur"; 1 L')->and($rows[1])->toHaveCount(25);
    });

    it('exports accented text in UTF-8', function () {
        $this->a->update(['name' => 'Épicerie Élite — Béjaïa']);

        expect(($this->rows)('format=csv')[1][6])->toBe('Épicerie Élite — Béjaïa')
            ->and(($this->rows)('format=xlsx')[1][6])->toBe('Épicerie Élite — Béjaïa');
    });
});

describe('formulas', function () {
    // §8.4 : toute cellule qui commencerait une formule est neutralisée, dans chaque format.
    $starts = ['=1+1', '+SUM(A1)', '-2+3', '@HYPERLINK("x")', "\tTAB", "\rCR"];

    it('neutralises every text cell in the XLSX and the CSV for Excel', function (string $text, string $format) {
        $this->orange->update(['name' => $text]);
        $this->a->update(['name' => $text]);
        $this->regional->update(['name' => $text]);
        $this->centre->update(['name' => $text]);
        $this->depot->update(['name' => $text]);
        Order::query()->where('number', 'RMK-261008-00004')->update(['delivery_note_ref' => $text]);
        $expected = "'".($format === 'xlsx' ? str_replace("\r", "\n", $text) : $text);

        $rows = ($this->rows)('format='.$format);
        $first = $rows[1];
        $shipped = array_values(array_filter($rows, fn (array $row) => $row[2] === 'RMK-261008-00004'))[0];

        expect([$first[6], $first[7], $first[8], $first[10], $first[22]])->toBe(array_fill(0, 5, $expected))
            ->and($shipped[24])->toBe($expected);
    })->with($starts)->with(['xlsx', 'csv']);

    it('neutralises the codes of the ERP format', function (string $text) {
        $this->a->update(['code' => $text]);
        $this->centre->update(['code' => $text]);
        $this->depot->update(['code' => $text]);
        $this->orange->update(['sku' => $text]);
        Order::query()->where('number', 'RMK-261008-00001')->update(['number' => $text]);

        $rows = documentsCsvRows(($this->download)('format=erp')->streamedContent());
        $first = $rows[1];

        expect([$first[2], $first[3], $first[4], $first[5], $first[6]])->toBe(array_fill(0, 5, "'".$text));
    })->with($starts);

    it('leaves negative-looking dates and plain text alone', function () {
        $rows = ($this->rows)('format=csv');

        expect($rows[1][0])->toBe('08/10/2026')->and($rows[1][6])->toBe('Épicerie El Baraka');
    });
});

describe('volume', function () {
    it('refuses an export above the row cap with 422 on filter, before sending any byte', function () {
        app()->bind(OrderExport::class, fn () => new OrderExport(app(ReasonCodes::class), 9));

        $response = ($this->download)('format=csv');

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['filter']]);
        expect($response->json('errors.filter.0'))->toContain('9 lignes');
    });

    it('accepts an export exactly at the row cap', function () {
        app()->bind(OrderExport::class, fn () => new OrderExport(app(ReasonCodes::class), 10));

        expect(($this->rows)('format=csv'))->toHaveCount(1 + 10);
    });

    it('counts the lines of an export in one query', function () {
        $export = app(OrderExport::class);

        expect($export->count($this->day, ExportFormat::Csv->allowedStatuses(), null))->toBe(10)
            ->and($export->count($this->day, ExportFormat::Erp->allowedStatuses(), null))->toBe(6)
            ->and($export->count($this->day, ExportFormat::Csv->allowedStatuses(), [$this->est->id]))->toBe(1)
            ->and($export->count($this->day, ExportFormat::Csv->allowedStatuses(), []))->toBe(0);
    });

    /**
     * Journée de 400 commandes de 5 lignes (2 000 lignes) : insertion directe, pour aller vite.
     */
    $bulkDay = function (): void {
        $day = BusinessDay::factory()->on('2026-10-12')->create();
        $products = Product::factory()->count(5)->create(['family_id' => $this->orange->family_id]);
        $clients = Client::factory()->count(20)->create(['region_id' => $this->centre->id]);
        $now = now()->toDateTimeString();
        foreach (range(1, 400) as $index) {
            $id = DB::table('orders')->insertGetId([
                'public_id' => strtoupper((string) Str::ulid()), 'number' => sprintf('RMK-261012-%05d', $index),
                'business_day_id' => $day->id, 'region_id' => $this->centre->id, 'client_id' => $clients[$index % 20]->id,
                'status' => $index % 2 === 0 ? 'VALIDATED' : 'SHIPPED', 'version' => 2, 'client_request_id' => (string) Str::uuid(),
                'submitted_at' => $now, 'decided_at' => $now, 'decided_by' => $this->regional->id, 'total_ht' => 4500, 'total_vat' => 855, 'total_ttc' => 5355,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('order_lines')->insert($products->map(fn (Product $product) => [
                'order_id' => $id, 'product_id' => $product->id, 'requested_qty' => 12, 'validated_qty' => 10, 'shipped_qty' => $index % 2 === 0 ? null : 10,
                'unit_price_ht' => '90.00', 'vat_rate' => '19.00', 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    };

    it('exports 2 000 lines in constant memory, whatever the format', function (string $format) use ($bulkDay) {
        $bulkDay->call($this);
        $url = '/api/v1/adv/days/2026-10-12/orders/export?format='.$format;
        $this->actingAs($this->adv);
        // Échauffement : classes, requête d'authentification, sérialiseurs.
        $this->get($this->url.'?format='.$format)->streamedContent();

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $body = $this->get($url)->streamedContent();
        $peak = memory_get_peak_usage() - $before;

        // Mesuré : environ 1,2 Mo, fichier capturé compris. Charger les 2 000 lignes d'un bloc (modèles et relations) en coûterait plus de 10.
        expect($peak)->toBeLessThan(4 * 1024 * 1024)
            ->and(strlen($body))->toBeGreaterThan(100_000);
    })->with(['xlsx', 'csv', 'erp']);

    it('counts 2 000 lines and reads them in chunks of orders, not line by line', function () use ($bulkDay) {
        $bulkDay->call($this);
        $day = BusinessDay::forDay('2026-10-12');
        $export = app(OrderExport::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $lines = iterator_to_array($export->lines($day, ExportFormat::Csv->allowedStatuses(), null), false);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Deux requêtes par lot de 100 commandes (4 lots), plus la lecture qui constate la fin.
        expect($lines)->toHaveCount(2000)->and($export->count($day, ExportFormat::Csv->allowedStatuses(), null))->toBe(2000)
            ->and($queries)->toBeLessThanOrEqual(2 * 5 + 1);
        // Les lignes d'une commande se suivent, dans l'ordre d'arrivée des commandes.
        expect(array_slice(array_map(fn ($line) => $line->order->number, $lines), 0, 6))
            ->toBe(['RMK-261012-00001', 'RMK-261012-00001', 'RMK-261012-00001', 'RMK-261012-00001', 'RMK-261012-00001', 'RMK-261012-00002']);
    });
});
