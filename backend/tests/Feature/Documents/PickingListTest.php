<?php

use App\Domain\Documents\Formats;
use App\Domain\Documents\Pallets;
use App\Domain\Documents\PickingBy;
use App\Domain\Documents\PickingList;
use App\Domain\Orders\OrderStatus;
use App\Models\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/DocumentsWorld.php';

// Bon de préparation consolidé (RG-A04, EF-ADV-05, I-84) : quantités
// validées de la journée, lignes à 0 exclues, par région ou par dépôt, par
// famille puis ordre, avec commandes et palettes ; PDF et XLSX.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    documentsWorld($this);
    $nbsp = Formats::NBSP;
    $this->nbsp = $nbsp;

    // Centre : A (validée) et B (en préparation) ; Ouest : O (préparée, une ligne à 0) ; Est : E (validée).
    documentsOrder($this, $this->a, 'RMK-261008-00001', 'validated', [[$this->orange, 40, 40], [$this->pomme, 15, 10], [$this->lben, 20, 20]]);
    documentsOrder($this, $this->b, 'RMK-261008-00002', 'inPreparation', [[$this->orange, 30, 30]]);
    documentsOrder($this, $this->o, 'RMK-261008-00003', 'prepared', [[$this->orange, 25, 25], [$this->pomme, 5, 0]]);
    documentsOrder($this, $this->e, 'RMK-261008-00004', 'validated', [[$this->lben, 100, 100]]);
    // Hors bon par défaut : expédiée, rejetée, annulée, expirée, envoyée.
    documentsOrder($this, $this->a, 'RMK-261008-00005', 'shipped', [[$this->orange, 7, 7]]);
    documentsOrder($this, $this->a, 'RMK-261008-00006', 'rejected', [[$this->orange, 99, null]]);
    documentsOrder($this, $this->a, 'RMK-261008-00007', 'cancelled', [[$this->orange, 98, 98]]);
    documentsOrder($this, $this->a, 'RMK-261008-00008', 'expired', [[$this->orange, 97, null]]);
    documentsOrder($this, $this->a, 'RMK-261008-00009', 'submitted', [[$this->orange, 96, null]]);

    $this->report = fn (PickingBy $by = PickingBy::Region, ?array $statuses = null, ?array $regionIds = null) => app(PickingList::class)
        ->build($this->day, $statuses ?? PickingList::defaultStatuses(), $by, $regionIds);
    $this->lines = fn (object $group): array => array_map(
        fn ($line) => [$line->sku, $line->quantity, $line->orders, $line->pallets === null ? null : [$line->pallets->full, $line->pallets->remainder, $line->pallets->decimal]],
        $group->lines,
    );
});

describe('pallets', function () {
    it('splits a quantity into full pallets, remainder in UV and a decimal equivalent', function (int $quantity, ?int $perPallet, ?array $expected) {
        $pallets = Pallets::of($quantity, $perPallet);

        expect($pallets === null ? null : [$pallets->full, $pallets->remainder, $pallets->decimal])->toBe($expected);
    })->with([
        'more than one pallet' => [130, 48, [2, 34, 2.71]],
        'exactly one pallet' => [48, 48, [1, 0, 1.0]],
        'less than one pallet' => [20, 60, [0, 20, 0.33]],
        'rounded up' => [25, 48, [0, 25, 0.52]],
        'zero' => [0, 48, [0, 0, 0.0]],
        'no pallet size' => [70, null, null],
        'zero pallet size' => [70, 0, null],
    ]);
});

describe('aggregation', function () {
    it('groups by region, ordered by code, with products by family then order', function () {
        $report = ($this->report)();

        expect(array_map(fn ($group) => [$group->label, $group->code], $report->groups))->toBe([['Centre', 'CENTRE'], ['Est', 'EST'], ['Ouest', 'OUEST']]);

        [$centre, $est, $ouest] = $report->groups;
        // Boissons (orange 10, pomme 20) avant Laitiers (lben), quel que soit l'ordre propre au produit.
        expect(($this->lines)($centre))->toBe([['P0042', 70, 2, [1, 22, 1.46]], ['P0057', 10, 1, null], ['P0100', 20, 1, [0, 20, 0.33]]])
            ->and(array_map(fn ($line) => $line->family, $centre->lines))->toBe(['Boissons', 'Boissons', 'Laitiers'])
            ->and(($this->lines)($est))->toBe([['P0100', 100, 1, [1, 40, 1.67]]])
            // La ligne de pomme validée à 0 n'apparaît pas.
            ->and(($this->lines)($ouest))->toBe([['P0042', 25, 1, [0, 25, 0.52]]]);
    });

    it('counts the distinct orders of a group, not the sum of its products', function () {
        [$centre] = ($this->report)()->groups;

        // A porte trois produits : le groupe compte A et B, soit deux commandes.
        expect($centre->totals->orders)->toBe(2)->and($centre->totals->products)->toBe(3);
    });

    it('totals each group and the whole document', function () {
        $report = ($this->report)();

        expect(array_map(fn ($group) => [$group->totals->products, $group->totals->quantity, $group->totals->orders, $group->totals->pallets], $report->groups))
            ->toBe([[3, 100, 2, 1.79], [1, 100, 1, 1.67], [1, 25, 1, 0.52]])
            ->and([$report->totals->products, $report->totals->quantity, $report->totals->orders, $report->totals->pallets])->toBe([3, 225, 4, 3.98]);
    });

    it('has no pallet total when no product has a pallet size', function () {
        $this->orange->update(['sales_units_per_pallet' => null]);
        $this->lben->update(['sales_units_per_pallet' => null]);

        $report = ($this->report)();

        expect($report->totals->pallets)->toBeNull()->and($report->groups[0]->totals->pallets)->toBeNull();
    });

    it('groups by the current depot of the region, with a « Sans dépôt » bucket listing its regions', function () {
        $report = ($this->report)(PickingBy::Depot);

        expect(array_map(fn ($group) => [$group->label, $group->code, $group->regions], $report->groups))
            ->toBe([['Dépôt de Blida', 'BLIDA', ['Centre', 'Ouest']], ['Sans dépôt', null, ['Est']]]);

        [$blida, $none] = $report->groups;
        expect(($this->lines)($blida))->toBe([['P0042', 95, 3, [1, 47, 1.98]], ['P0057', 10, 1, null], ['P0100', 20, 1, [0, 20, 0.33]]])
            ->and(($this->lines)($none))->toBe([['P0100', 100, 1, [1, 40, 1.67]]])
            ->and([$blida->totals->quantity, $blida->totals->orders, $blida->totals->pallets])->toBe([125, 3, 2.31])
            ->and([$report->totals->quantity, $report->totals->orders, $report->totals->pallets])->toBe([225, 4, 3.98]);
    });

    it('follows the current depot of the region, not the depot at the time of the order (I-84)', function () {
        $this->est->update(['depot_id' => $this->depot->id]);

        $report = ($this->report)(PickingBy::Depot);

        expect(array_map(fn ($group) => $group->label, $report->groups))->toBe(['Dépôt de Blida'])
            ->and($report->groups[0]->regions)->toBe(['Centre', 'Est', 'Ouest']);
    });

    it('includes the statuses asked for, validated and later only', function () {
        $shipped = ($this->report)(PickingBy::Region, [OrderStatus::Shipped]);
        $validated = ($this->report)(PickingBy::Region, [OrderStatus::Validated]);

        expect($shipped->groups)->toHaveCount(1)
            ->and(($this->lines)($shipped->groups[0]))->toBe([['P0042', 7, 1, [0, 7, 0.15]]])
            // Validées : A (centre) et E (est) seulement, ni B (en préparation) ni O (préparée).
            ->and(array_map(fn ($group) => [$group->code, $group->totals->quantity], $validated->groups))->toBe([['CENTRE', 70], ['EST', 100]]);
    });

    it('never counts cancelled, rejected, expired or sent orders', function () {
        $everything = ($this->report)(PickingBy::Region, OrderStatus::validated());

        // 225 déjà vus + l'expédiée (7) : rien des commandes annulée (98), rejetée (99), expirée (97) ni envoyée (96).
        expect($everything->totals->quantity)->toBe(232);
    });

    it('restricts to the regions given, an empty list meaning none (RG-S05)', function () {
        $est = ($this->report)(PickingBy::Region, null, [$this->est->id]);
        $none = ($this->report)(PickingBy::Region, null, []);

        expect(array_map(fn ($group) => $group->code, $est->groups))->toBe(['EST'])
            ->and($none->isEmpty())->toBeTrue()->and($none->totals->quantity)->toBe(0)->and($none->totals->pallets)->toBeNull();
    });

    it('reads the orders of the day asked only', function () {
        $other = BusinessDay::factory()->on('2026-10-07')->create();

        $report = app(PickingList::class)->build($other, PickingList::defaultStatuses(), PickingBy::Region, null);

        expect($report->isEmpty())->toBeTrue();
    });

    it('uses a fixed number of queries whatever the volume', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->report)(PickingBy::Depot);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $before = $count();
        foreach (range(1, 40) as $index) {
            documentsOrder($this, $this->b, sprintf('RMK-261008-%05d', 100 + $index), 'validated', [[$this->orange, 3, 3], [$this->lben, 2, 2], [$this->pomme, 1, 1]]);
        }

        expect($count())->toBe($before)->and($before)->toBeLessThanOrEqual(6);
    });
});

describe('PDF document', function () {
    it('renders the header, the groups, the pallets and the totals', function () {
        $report = ($this->report)();
        $html = app(PickingList::class)->view($report)->render();
        $text = documentsText($html);

        expect($text)->toContain('Bon de préparation')
            ->toContain('Journée 08/10/2026')
            ->toContain('Livraison 09/10/2026')
            ->toContain('Par région')
            ->toContain('Statuts inclus Validée, En préparation, Préparée')
            ->toContain('Généré le 08/10/2026 09:30')
            // Groupes : un titre par région.
            ->toContain('Centre (CENTRE)')->toContain('Est (EST)')->toContain('Ouest (OUEST)')
            // Familles, produits, UV.
            ->toContain('Boissons')->toContain('Laitiers')
            ->toContain('P0042 Jus Orange 1 L Fardeau 6 × 1 L 70 2 1 22 1,46')
            // Sans colisage : « — » pour les trois colonnes de palettes.
            ->toContain('P0057 Jus Pomme 1 L Fardeau 6 × 1 L 10 1 — — —')
            ->toContain('P0100 Lben 1 L Carton 12 × 1 L 20 1 0 20 0,33')
            // Totaux par groupe et total général.
            ->toContain('Total Centre 100 2 1,79')
            ->toContain('Total général')
            ->toContain('Produits : 3')->toContain('Quantité (UV) : 225')->toContain('Commandes : 4')->toContain('Palettes (équiv.) : 3,98')
            ->not->toContain('Aucune commande');
    });

    it('lists the regions of a depot group', function () {
        $text = documentsText(app(PickingList::class)->view(($this->report)(PickingBy::Depot))->render());

        expect($text)->toContain('Dépôt de Blida (BLIDA)')->toContain('Régions : Centre, Ouest')->toContain('Sans dépôt')->toContain('Par dépôt')
            ->toContain('Total Sans dépôt 100 1 1,67');
    });

    it('formats thousands with a non-breaking space', function () {
        $this->orange->update(['sales_units_per_pallet' => 1000]);
        documentsOrder($this, $this->b, 'RMK-261008-00150', 'validated', [[$this->orange, 12000, 12000]]);

        $text = documentsText(app(PickingList::class)->view(($this->report)())->render());

        expect($text)->toContain("12{$this->nbsp}070");
    });

    it('prints « Aucune commande » for a day without orders', function () {
        $empty = BusinessDay::factory()->on('2026-10-09')->create();
        $report = app(PickingList::class)->build($empty, PickingList::defaultStatuses(), PickingBy::Region, null);
        $text = documentsText(app(PickingList::class)->view($report)->render());

        expect($text)->toContain('Aucune commande')->toContain('Journée 09/10/2026')->not->toContain('Total général');
    });

    it('escapes the names it prints', function () {
        $this->pomme->update(['name' => 'Jus <b>Pomme</b>']);

        $html = app(PickingList::class)->view(($this->report)())->render();

        expect($html)->toContain('Jus &lt;b&gt;Pomme&lt;/b&gt;')->not->toContain('<b>Pomme</b>');
    });

    it('converts to a real PDF', function () {
        $pdf = app(PickingList::class)->pdf(($this->report)());

        expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(strlen($pdf))->toBeGreaterThan(2000);
    });

    it('converts an empty day to a PDF too', function () {
        $empty = BusinessDay::factory()->on('2026-10-09')->create();
        $pdf = app(PickingList::class)->pdf(app(PickingList::class)->build($empty, PickingList::defaultStatuses(), PickingBy::Region, null));

        expect(substr($pdf, 0, 5))->toBe('%PDF-');
    });
});

describe('XLSX document', function () {
    it('writes the header block, one flat table and the totals, with numeric cells', function () {
        $response = app(PickingList::class)->response(($this->report)(), 'xlsx');
        $rows = documentsSheetRows(documentsBody($response));

        expect($rows[0][0])->toBe('Bon de préparation')
            ->and(array_slice($rows[1], 0, 2))->toBe(['Journée', '08/10/2026'])
            ->and(array_slice($rows[2], 0, 2))->toBe(['Livraison', '09/10/2026'])
            ->and(array_slice($rows[3], 0, 2))->toBe(['Regroupement', 'Par région'])
            ->and(array_slice($rows[4], 0, 2))->toBe(['Statuts inclus', 'Validée, En préparation, Préparée'])
            ->and(array_slice($rows[5], 0, 2))->toBe(['Généré le', '08/10/2026 09:30'])
            ->and($rows[7])->toBe(['Groupe', 'Famille', 'SKU', 'Produit', 'UV', 'Quantité (UV)', 'Commandes', 'Palettes pleines', 'Reste (UV)', 'Palettes (équiv.)']);

        // Première ligne du tableau : Centre, orange, quantités et palettes numériques.
        expect($rows[8])->toBe(['Centre', 'Boissons', 'P0042', 'Jus Orange 1 L', 'Fardeau 6 × 1 L', 70, 2, 1, 22, 1.46])
            // Sans colisage : « — ».
            ->and($rows[9])->toBe(['Centre', 'Boissons', 'P0057', 'Jus Pomme 1 L', 'Fardeau 6 × 1 L', 10, 1, '—', '—', '—'])
            ->and($rows[11][0])->toBe('Total Centre')->and($rows[11][5])->toBe(100)->and($rows[11][6])->toBe(2)->and($rows[11][9])->toBe(1.79);

        $last = end($rows);
        expect($last[0])->toBe('Total général')->and($last[5])->toBe(225)->and($last[6])->toBe(4)->and($last[9])->toBe(3.98);
    });

    it('names the depot and its regions when grouping by depot', function () {
        $rows = documentsSheetRows(documentsBody(app(PickingList::class)->response(($this->report)(PickingBy::Depot), 'xlsx')));

        expect($rows[8][0])->toBe('Dépôt de Blida (Centre, Ouest)')->and($rows[8][5])->toBe(95);
        $groups = array_values(array_unique(array_column(array_slice($rows, 8), 0)));
        expect($groups)->toContain('Sans dépôt (Est)');
    });

    it('prints « Aucune commande » when there is nothing to prepare', function () {
        $empty = BusinessDay::factory()->on('2026-10-09')->create();
        $report = app(PickingList::class)->build($empty, PickingList::defaultStatuses(), PickingBy::Region, null);

        $rows = documentsSheetRows(documentsBody(app(PickingList::class)->response($report, 'xlsx')));

        expect(end($rows)[0])->toBe('Aucune commande');
    });

    it('neutralises every text that would start a formula', function (string $text) {
        $this->orange->update(['name' => $text]);

        $rows = documentsSheetRows(documentsBody(app(PickingList::class)->response(($this->report)(), 'xlsx')));

        // La lecture XML ramène un retour chariot à un saut de ligne.
        expect($rows[8][3])->toBe("'".str_replace("\r", "\n", $text));
    })->with(['=1+1', '+SUM(A1)', '-2+3', '@HYPERLINK("x")', "\tTAB", "\rCR"]);
});

describe('download', function () {
    it('is an attachment that is never cached, named after the day', function (string $format, string $type, string $name) {
        $response = app(PickingList::class)->response(($this->report)(), $format);

        expect($response->headers->get('Content-Type'))->toStartWith($type)
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain($name)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    })->with([
        'pdf' => ['pdf', 'application/pdf', 'bon-de-preparation-2026-10-08.pdf'],
        'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bon-de-preparation-2026-10-08.xlsx'],
    ]);
});
