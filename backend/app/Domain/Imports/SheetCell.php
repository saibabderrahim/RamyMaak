<?php

namespace App\Domain\Imports;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Valeur d'une cellule lue dans un fichier importé : valeur brute (texte,
 * nombre, booléen), texte tel qu'affiché dans Excel (zéros de tête d'un
 * format « 0000000000 » conservés), ou code d'erreur Excel (#N/A…) renvoyé
 * par une formule. Les formules sont lues comme valeurs calculées (§8.4).
 */
final class SheetCell
{
    private function __construct(
        public readonly string|int|float|bool|null $value,
        public readonly ?string $text,
        public readonly ?string $error = null,
        private readonly int $calendar = ExcelDate::CALENDAR_WINDOWS_1900,
    ) {}

    public static function empty(): self
    {
        return new self(null, null);
    }

    public static function text(string $text): self
    {
        return new self($text, $text);
    }

    public static function number(int|float $value, string $text, int $calendar): self
    {
        return new self($value, $text, calendar: $calendar);
    }

    public static function boolean(bool $value): self
    {
        return new self($value, $value ? 'VRAI' : 'FAUX');
    }

    public static function failure(string $error): self
    {
        return new self(null, null, $error);
    }

    public function isEmpty(): bool
    {
        return $this->value === null && $this->error === null;
    }

    public function isNumeric(): bool
    {
        return is_int($this->value) || is_float($this->value);
    }

    /** Valeur citée dans un message d'erreur : « 12,5 » plutôt que « 12.5 ». */
    public function display(): string
    {
        $text = (string) ($this->error ?? $this->text ?? '');
        if ($this->isNumeric()) {
            $text = str_replace('.', ',', $text);
        }

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 59).'…' : $text;
    }

    /**
     * Date d'une cellule date Excel (numéro de série, calendrier 1900 ou
     * 1904 du classeur) ou d'un texte JJ/MM/AAAA (AAAA-MM-JJ accepté).
     */
    public function date(): ?CarbonImmutable
    {
        if ($this->isNumeric()) {
            if ($this->value < 1 || $this->value >= 2958466) {
                return null;
            }

            try {
                $moment = ExcelDate::excelToDateTimeObject(floor((float) $this->value), 'UTC', $this->calendar);
            } catch (Throwable) {
                return null;
            }

            return BusinessClock::parseDay($moment->format('Y-m-d'));
        }

        if (! is_string($this->value)) {
            return null;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $this->value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? BusinessClock::parseDay(sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]))
                : null;
        }

        return BusinessClock::parseDay($this->value);
    }
}
