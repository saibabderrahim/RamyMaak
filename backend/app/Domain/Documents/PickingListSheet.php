<?php

namespace App\Domain\Documents;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Bon de préparation au format XLSX (RG-A04), écrit en flux par OpenSpout :
 * un bloc d'en-tête (journée, livraison, regroupement, statuts, heure de
 * génération), puis un tableau à plat (une ligne par groupe et produit, donc
 * filtrable dans Excel), un total par groupe et le total général. Quantités
 * et palettes sont numériques ; « — » sans colisage. Journée sans commande :
 * « Aucune commande ».
 */
final class PickingListSheet
{
    public const CONTENT_TYPE = Cells::CONTENT_TYPE;

    private const WIDTHS = [26, 22, 12, 40, 18, 14, 12, 14, 12, 16];

    public function write(PickingReport $report, string $target = 'php://output'): void
    {
        $options = new Options;
        // Dossier de travail propre à ce téléchargement, retiré même si la copie est interrompue.
        $folder = WorkFolder::create();
        $options->setTempFolder($folder);
        foreach (self::WIDTHS as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($target);

        try {
            $writer->getCurrentSheet()->setName((string) __('documents.picking.sheet'));
            $this->head($writer, $report);

            if ($report->isEmpty()) {
                $writer->addRow(Cells::row([Cells::text((string) __('documents.picking.empty'), Cells::bold())]));

                return;
            }

            $bold = Cells::bold();
            $writer->addRow(Cells::row(array_map(
                fn (string $label) => Cells::text($label, Cells::header()),
                (array) __('documents.picking.columns'),
            )));
            foreach ($report->groups as $group) {
                $label = $report->by === PickingBy::Depot ? $group->label.' ('.implode(', ', $group->regions).')' : $group->label;
                foreach ($group->lines as $line) {
                    $writer->addRow(Cells::row([
                        Cells::text($label),
                        Cells::text($line->family),
                        Cells::text($line->sku),
                        Cells::text($line->name),
                        Cells::text($line->unitLabel),
                        Cells::number($line->quantity),
                        Cells::number($line->orders),
                        ...$this->pallets($line->pallets),
                    ]));
                }
                $writer->addRow($this->total((string) __('documents.picking.total_group', ['group' => $group->label]), $group->totals, $bold));
            }
            $writer->addRow($this->total((string) __('documents.picking.total'), $report->totals, $bold));
        } finally {
            try {
                $writer->close();
            } finally {
                WorkFolder::remove($folder);
            }
        }
    }

    private function head(Writer $writer, PickingReport $report): void
    {
        $bold = Cells::bold();
        $writer->addRow(Cells::row([Cells::text((string) __('documents.picking.title'), (new Style)->setFontBold()->setFontSize(14))]));
        foreach ([
            'day' => Formats::date($report->day),
            'delivery' => Formats::date($report->deliveryDate),
            'by' => $report->by->label(),
            'statuses' => $report->statusLabels(),
            'generated' => Formats::dateTime($report->generatedAt),
        ] as $key => $value) {
            $writer->addRow(Cells::row([Cells::text((string) __('documents.picking.head.'.$key), $bold), Cells::text($value)]));
        }
        $writer->addRow(Cells::row([]));
    }

    /**
     * @return list<Cell>
     */
    private function pallets(?Pallets $pallets): array
    {
        if ($pallets === null) {
            return [Cells::text('—'), Cells::text('—'), Cells::text('—')];
        }

        return [Cells::number($pallets->full), Cells::number($pallets->remainder), Cells::number($pallets->decimal, (new Style)->setFormat('0.00'))];
    }

    private function total(string $label, PickingTotals $totals, Style $bold): Row
    {
        return Cells::row([
            Cells::text($label, $bold),
            Cells::text(null, $bold),
            Cells::text(null, $bold),
            Cells::text(null, $bold),
            Cells::text(null, $bold),
            Cells::number($totals->quantity, $bold),
            Cells::number($totals->orders, $bold),
            Cells::text(null, $bold),
            Cells::text(null, $bold),
            $totals->pallets === null ? Cells::text('—', $bold) : Cells::number($totals->pallets, (new Style)->setFontBold()->setFormat('0.00')),
        ]);
    }
}
