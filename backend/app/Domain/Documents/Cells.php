<?php

namespace App\Domain\Documents;

use App\Support\Csv;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Cellules XLSX des documents. Une chaîne n'est jamais confiée à
 * `Cell::fromValue`, qui lirait « =… » comme une formule : le texte devient
 * toujours une cellule texte, neutralisée comme dans les exports CSV si elle
 * commencerait une formule (`=`, `+`, `-`, `@`, tabulation, retour chariot ;
 * §8.4, ENF-13). Les nombres restent des cellules numériques.
 */
final class Cells
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Style des cellules d'en-tête : gras sur fond gris. */
    public static function header(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('FFE4E7EB')->setShouldWrapText();
    }

    public static function bold(): Style
    {
        return (new Style)->setFontBold();
    }

    public static function text(?string $value, ?Style $style = null): Cell
    {
        $value = Csv::sanitize($value);

        return $value === '' ? new EmptyCell(null, $style) : new StringCell($value, $style);
    }

    public static function number(int|float|null $value, ?Style $style = null): Cell
    {
        return $value === null ? new EmptyCell(null, $style) : new NumericCell($value, $style);
    }

    /**
     * @param  list<Cell>  $cells
     */
    public static function row(array $cells): Row
    {
        return new Row($cells);
    }
}
