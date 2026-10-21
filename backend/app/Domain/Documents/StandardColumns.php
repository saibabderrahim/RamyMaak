<?php

namespace App\Domain\Documents;

use Carbon\CarbonImmutable;

/**
 * Colonnes de l'export pour Excel (XLSX et CSV, contrat du lot 6, §6) : une
 * ligne par ligne de commande, dates JJ/MM/AAAA, heure d'Alger. Un nombre
 * reste un nombre (`int` ou `float`), le texte est du texte : chaque format
 * décide de leur écriture (cellule numérique, virgule décimale).
 */
final class StandardColumns
{
    /** Ordre des colonnes, libellés dans `documents.export.columns`. */
    public const KEYS = [
        'day', 'delivery', 'number', 'status', 'adjusted', 'client_code', 'client', 'region', 'depot', 'sku', 'product', 'unit',
        'requested', 'validated', 'shipped', 'adjustment_reason', 'gap_reason', 'price_ht', 'vat_rate', 'amount_ht',
        'submitted_at', 'decided_at', 'decided_by', 'shipped_at', 'delivery_note',
    ];

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_map(fn (string $key) => (string) __('documents.export.columns.'.$key), self::KEYS);
    }

    /**
     * @return list<string|int|float|null>
     */
    public static function values(ExportLine $line): array
    {
        $order = $line->order;

        return [
            Formats::date($order->day),
            $order->deliveryDate === null ? null : Formats::date($order->deliveryDate),
            $order->number,
            $order->status->label(),
            (string) __($order->adjusted ? 'documents.export.yes' : 'documents.export.no'),
            $order->clientCode,
            $order->clientName,
            $order->regionName,
            $order->depotName,
            $line->sku,
            $line->product,
            $line->unitLabel,
            $line->requested,
            $line->validated,
            $line->shipped,
            $line->adjustmentReason,
            $line->gapReason,
            $line->unitPriceHt,
            $line->vatRate,
            $line->amountHt,
            self::moment($order->submittedAt),
            self::moment($order->decidedAt),
            $order->decidedBy,
            self::moment($order->shippedAt),
            $order->deliveryNoteRef,
        ];
    }

    private static function moment(?CarbonImmutable $moment): ?string
    {
        return $moment === null ? null : Formats::dateTime($moment);
    }
}
