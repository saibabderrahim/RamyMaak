<?php

namespace App\Domain\Documents;

use App\Domain\Orders\OrderStatus;

/**
 * Formats de l'export des commandes (RG-A04, EF-ADV-07, I-85) : XLSX et CSV
 * pour Excel (tous les statuts par défaut), et le format ERP provisoire
 * `erp-v0` (Q-14), limité à Validée … Expédiée.
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case Erp = 'erp';

    public function layout(): ExportLayout
    {
        return match ($this) {
            self::Xlsx => new XlsxLayout,
            self::Csv => new CsvLayout,
            self::Erp => new ErpExportLayout,
        };
    }

    /**
     * Statuts admis par le format ; l'export ERP ne reprend jamais une
     * commande annulée, rejetée, expirée ni encore envoyée.
     *
     * @return list<OrderStatus>
     */
    public function allowedStatuses(): array
    {
        return match ($this) {
            self::Erp => [OrderStatus::Validated, OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped],
            default => OrderStatus::cases(),
        };
    }

    /**
     * Statuts exportés sans filtre `filter[status]` : tous ceux que le format admet.
     *
     * @return list<OrderStatus>
     */
    public function defaultStatuses(): array
    {
        return $this->allowedStatuses();
    }
}
