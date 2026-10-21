<?php

namespace App\Domain\Documents;

use App\Domain\Orders\OrderStatus;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\BusinessClock;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bon de commande d'une commande de la file ADV (RG-A04, I-86) : identité
 * du client (NIF, RC, adresse), numéro, journée et date de livraison,
 * lignes (demandé, validé, motif, expédié et écart quand ils existent, prix
 * HT, TVA, montant HT sur la quantité validée) et totaux. Une commande
 * annulée porte la mention « ANNULÉE ». Montants indicatifs (RG-P05).
 *
 * Les droits et le périmètre sont contrôlés par la requête de l'appelant ;
 * la mise en page est celle du gabarit `pdf.order`, réutilisable pour une
 * impression de plusieurs commandes plus tard.
 */
final class OrderPdf
{
    public function __construct(private readonly ReasonCodes $reasons) {}

    /** Bon de commande en pièce jointe : `bon-de-commande-RMK-….pdf`, jamais mis en cache. */
    public function response(Order $order): Response
    {
        return Downloads::content('bon-de-commande-'.$order->number.'.pdf', 'application/pdf', $this->pdf($order));
    }

    public function pdf(Order $order): string
    {
        return Pdf::loadView('pdf.order', $this->data($order))
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->addInfo(['Title' => (string) __('documents.order.title').' '.$order->number])
            ->output();
    }

    /** Gabarit rempli (HTML avant conversion en PDF). */
    public function view(Order $order): View
    {
        return view('pdf.order', $this->data($order));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Order $order): array
    {
        $order->loadMissing(['businessDay', 'client', 'region.depot', 'decidedBy:id,name', 'lines.product']);
        $lines = $order->lines->map(fn (OrderLine $line) => $this->line($line))->all();
        $cancelled = $order->status === OrderStatus::Cancelled;

        return [
            'order' => $order,
            'cancelled' => $cancelled,
            'cancellation' => $cancelled ? $this->reasons->label(ReasonType::Cancellation, $order->decision_reason_code) : null,
            'client' => $order->client,
            'region' => $order->region,
            'depot' => $order->region->depot,
            'day' => $order->businessDay->day,
            'deliveryDate' => $order->businessDay->delivery_date,
            'lines' => $lines,
            // Colonnes d'expédition seulement quand une quantité a été saisie ou confirmée.
            'shipping' => $order->lines->contains(fn (OrderLine $line) => $line->shipped_qty !== null),
            'generatedAt' => BusinessClock::now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(OrderLine $line): array
    {
        $quantity = $line->validated_qty ?? $line->requested_qty;

        return [
            'sku' => $line->product->sku,
            'name' => $line->product->name,
            'unit' => $line->product->sales_unit_label,
            'requested' => $line->requested_qty,
            'validated' => $line->validated_qty,
            'reason' => $this->reasons->label(ReasonType::Adjustment, $line->adjustment_reason_code),
            'comment' => $line->adjustment_comment,
            'shipped' => $line->shipped_qty,
            // Écart d'expédition : manque par rapport à la quantité validée.
            'gap' => $line->shipped_qty !== null && $line->validated_qty !== null && $line->shipped_qty < $line->validated_qty
                ? $line->validated_qty - $line->shipped_qty
                : null,
            'gapReason' => $this->reasons->label(ReasonType::ShippingGap, $line->shipping_gap_reason_code),
            'price' => Money::amount(Money::cents($line->unit_price_ht)),
            'vat' => $line->vat_rate,
            'amount' => Money::amount(Money::cents($line->unit_price_ht) * $quantity),
        ];
    }
}
