<?php

namespace App\Domain\Documents;

use App\Models\BusinessDay;
use App\Support\BusinessClock;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export XLSX (EF-ADV-07), écrit en flux par OpenSpout : mémoire constante
 * quel que soit le nombre de lignes, cellules numériques pour les
 * quantités et les montants, texte neutralisé (§8.4), en-tête figé avec
 * filtre automatique. Une seconde feuille porte la mention des montants
 * indicatifs (RG-P05).
 */
final class XlsxLayout implements ExportLayout
{
    private const WIDTHS = [12, 12, 20, 16, 10, 12, 32, 18, 18, 12, 38, 18, 10, 10, 10, 24, 24, 12, 9, 16, 17, 17, 22, 17, 16];

    public function contentType(): string
    {
        return Cells::CONTENT_TYPE;
    }

    public function filename(BusinessDay $day): string
    {
        return 'commandes-'.$day->dayKey().'.xlsx';
    }

    public function write(iterable $lines, string $target = 'php://output'): void
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
            $sheet = $writer->getCurrentSheet();
            $sheet->setName((string) __('documents.export.sheet'));
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));

            $header = Cells::header();
            $writer->addRow(Cells::row(array_map(fn (string $label) => Cells::text($label, $header), StandardColumns::headers())));

            $money = (new Style)->setFormat('#,##0.00');
            foreach ($lines as $line) {
                $writer->addRow(Cells::row(array_map(
                    fn (string|int|float|null $value): Cell => is_float($value) ? Cells::number($value, $money) : (is_int($value) ? Cells::number($value) : Cells::text($value)),
                    StandardColumns::values($line),
                )));
            }

            $sheet->setAutoFilter(new AutoFilter(0, 1, count(self::WIDTHS) - 1, max(2, $writer->getWrittenRowCount())));

            // Les montants sont indicatifs (RG-P05) : la mention vit sur une seconde feuille, le tableau reste à plat.
            $writer->addNewSheetAndMakeItCurrent()->setName((string) __('documents.export.info_sheet'));
            $writer->addRow(Cells::row([Cells::text((string) __('documents.export.info_title'), Cells::bold())]));
            $writer->addRow(Cells::row([Cells::text(Formats::amountNotice())]));
            $writer->addRow(Cells::row([Cells::text((string) __('documents.generated_at', ['date' => Formats::dateTime(BusinessClock::now())]))]));
        } finally {
            try {
                $writer->close();
            } finally {
                WorkFolder::remove($folder);
            }
        }
    }
}
