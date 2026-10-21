<?php

use App\Domain\Documents\Formats;
use App\Domain\Documents\OrderPdf;
use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/DocumentsWorld.php';

// Bon de commande PDF d'une commande de la file ADV (RG-A04, I-86) :
// identité du client, lignes (demandé, validé, motif, expédié et écart),
// montants sur les quantités validées, mention RG-P05, « ANNULÉE ».

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    documentsWorld($this);
    $this->nbsp = Formats::NBSP;

    // 40 × 900,00 + 10 × 900,00 = 45 000,00 HT ; TVA 19 % puis 9 % = 7 650,00.
    $this->order = documentsOrder($this, $this->a, 'RMK-261008-00042', 'validated', [[$this->orange, 40, 40], [$this->pomme, 15, 10]], [
        'total_ht' => '45000.00', 'total_vat' => '7650.00', 'total_ttc' => '52650.00', 'is_adjusted' => true, 'client_comment' => 'Livrer le matin',
    ]);
    $this->order->lines()->where('product_id', $this->pomme->id)->update(['adjustment_reason_code' => 'QUOTA_INSUFFISANT', 'adjustment_comment' => 'Reste région limité']);
    $this->text = fn (Order $order): string => documentsText(app(OrderPdf::class)->view($order->fresh())->render());
});

describe('content', function () {
    it('shows the client identity, the order and the day', function () {
        $text = ($this->text)($this->order);

        expect($text)->toContain('Bon de commande')
            ->toContain('RMK-261008-00042')
            ->toContain('Épicerie El Baraka')->toContain('C0012')
            ->toContain('001234567890123')->toContain('16/00-1234567B21')
            ->toContain('12 rue Didouche Mourad, Alger-Centre, Alger')->toContain('0550123456')
            // Journée et date de livraison en JJ/MM/AAAA.
            ->toContain('Journée 08/10/2026')->toContain('Livraison 09/10/2026')
            ->toContain('Statut Validée')->toContain('Région · dépôt Centre · Dépôt de Blida')
            // Instants en heure d'Alger (UTC + 1) : 07:15 UTC puis 11:45 UTC.
            ->toContain('Envoyée le 08/10/2026 08:15')->toContain('Validée le 08/10/2026 12:45 · Karim Régional');
    });

    it('lists the lines with requested, validated, reason, price, VAT and amount on the validated quantity', function () {
        $text = ($this->text)($this->order);

        expect($text)
            ->toContain("P0042 Jus Orange 1 L Fardeau 6 × 1 L 40 40 — 900,00 19{$this->nbsp}% 36{$this->nbsp}000,00")
            // Quantité réduite : motif (libellé), commentaire, montant sur 10 UV et non sur 15.
            ->toContain("P0057 Jus Pomme 1 L Fardeau 6 × 1 L 15 10 Quota insuffisant Reste région limité 900,00 9{$this->nbsp}% 9{$this->nbsp}000,00");
    });

    it('shows the totals in DA with the indicative amounts notice (RG-P05)', function () {
        $text = ($this->text)($this->order);

        expect($text)
            ->toContain("Total HT 45{$this->nbsp}000,00{$this->nbsp}DA")
            ->toContain("TVA 7{$this->nbsp}650,00{$this->nbsp}DA")
            ->toContain("Total TTC 52{$this->nbsp}650,00{$this->nbsp}DA")
            ->toContain('Montants indicatifs : la facture ERP fait foi.')
            ->toContain('Commentaire du client : Livrer le matin')
            ->not->toContain('ANNULÉE');
    });

    it('hides the shipping columns until a shipped quantity exists', function () {
        $text = ($this->text)($this->order);

        expect($text)->not->toContain('Expédié')->not->toContain('Écart');
    });

    it('shows shipped quantities, the gap and its reason once prepared', function () {
        $prepared = documentsOrder($this, $this->b, 'RMK-261008-00043', 'shipped', [[$this->orange, 20, 20], [$this->pomme, 10, 10]], ['delivery_note_ref' => 'BL-0042', 'shipped_at' => '2026-10-08 15:00:00']);
        $prepared->lines()->where('product_id', $this->pomme->id)->update(['shipped_qty' => 8, 'shipping_gap_reason_code' => 'RUPTURE']);

        $text = ($this->text)($prepared);

        expect($text)->toContain('Expédié')->toContain('Écart')
            ->toContain("P0042 Jus Orange 1 L Fardeau 6 × 1 L 20 20 — 20 — 900,00 19{$this->nbsp}% 18{$this->nbsp}000,00")
            ->toContain("P0057 Jus Pomme 1 L Fardeau 6 × 1 L 10 10 — 8 −2 · Rupture de stock 900,00 9{$this->nbsp}% 9{$this->nbsp}000,00")
            ->toContain('Statut Expédiée')->toContain('Expédiée le 08/10/2026 16:00')->toContain('Bon de livraison BL-0042');
    });

    it('prints the « ANNULÉE » banner and the reason on a cancelled order', function () {
        $cancelled = documentsOrder($this, $this->b, 'RMK-261008-00044', 'cancelled', [[$this->orange, 20, 20]], [
            'decided_at' => '2026-10-08 11:00:00', 'decision_reason_code' => 'RUPTURE', 'total_ht' => '18000.00', 'total_vat' => '3420.00', 'total_ttc' => '21420.00',
        ]);

        $text = ($this->text)($cancelled);

        // Montants validés gardés (I-74), mention de l'annulation.
        expect($text)->toContain('ANNULÉE')->toContain('Commande annulée : Rupture de stock')->toContain('Statut Annulée')
            ->toContain("Total HT 18{$this->nbsp}000,00{$this->nbsp}DA");
    });

    it('prints a validated quantity of 0 and keeps the amount at 0', function () {
        $order = documentsOrder($this, $this->b, 'RMK-261008-00045', 'validated', [[$this->orange, 20, 20], [$this->pomme, 10, 0]]);

        $text = ($this->text)($order);

        expect($text)->toContain("P0057 Jus Pomme 1 L Fardeau 6 × 1 L 10 0 — 900,00 9{$this->nbsp}% 0,00");
    });

    it('escapes what it prints', function () {
        $this->a->update(['name' => 'Épicerie <script>alert(1)</script>']);

        $html = app(OrderPdf::class)->view($this->order->fresh())->render();

        expect($html)->toContain('Épicerie &lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>');
    });

    it('does not break on a client without NIF, RC or address', function () {
        $this->a->update(['nif' => null, 'rc' => null, 'address' => null, 'commune' => null, 'wilaya' => null, 'phone' => null]);

        expect(($this->text)($this->order))->toContain('NIF —')->toContain('RC —')->toContain('Adresse —');
    });
});

describe('download', function () {
    it('is a PDF attachment named after the order number, never cached', function () {
        $response = app(OrderPdf::class)->response($this->order);

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('bon-de-commande-RMK-261008-00042.pdf')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
            ->and(strlen((string) $response->getContent()))->toBeGreaterThan(2000);
    });

    it('converts a cancelled and a shipped order to a PDF as well', function () {
        $cancelled = documentsOrder($this, $this->b, 'RMK-261008-00046', 'cancelled', [[$this->orange, 20, 20]], ['decision_reason_code' => 'AUTRE']);
        $shipped = documentsOrder($this, $this->b, 'RMK-261008-00047', 'shipped', [[$this->orange, 20, 20]]);

        foreach ([$cancelled, $shipped] as $order) {
            expect(substr(app(OrderPdf::class)->pdf($order), 0, 5))->toBe('%PDF-');
        }
    });

    it('paginates an order of many lines', function () {
        $products = Product::factory()->count(90)->create(['family_id' => $this->orange->family_id]);
        $big = documentsOrder($this, $this->b, 'RMK-261008-00050', 'validated', $products->map(fn ($product) => [$product, 5, 5])->all());

        $pdf = app(OrderPdf::class)->pdf($big);

        expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(preg_match_all('#/Type\s*/Page\b#', $pdf))->toBeGreaterThan(2);
    });

    it('reads a fixed number of rows whatever the number of lines', function () {
        $count = function (Order $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(OrderPdf::class)->view(Order::query()->findOrFail($order->id))->render();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $small = documentsOrder($this, $this->b, 'RMK-261008-00048', 'validated', [[$this->orange, 1, 1]]);
        $products = Product::factory()->count(25)->create(['family_id' => $this->orange->family_id]);
        $large = documentsOrder($this, $this->b, 'RMK-261008-00049', 'validated', $products->map(fn ($product) => [$product, 5, 5])->all());

        expect($count($large))->toBe($count($small))->and($count($small))->toBeLessThanOrEqual(8);
    });
});
