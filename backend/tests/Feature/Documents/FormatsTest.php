<?php

use App\Domain\Documents\Formats;
use Carbon\CarbonImmutable;

// Formats des documents (ENF-13, RG-P05) : DA à deux décimales avec une
// espace insécable pour les milliers, JJ/MM/AAAA, heure d'Alger.

describe('amounts', function () {
    it('writes DA with two decimals, a decimal comma and non-breaking spaces', function (mixed $value, string $expected) {
        expect(Formats::amount($value))->toBe($expected);
    })->with([
        'entier' => [900, "900,00\u{00A0}DA"],
        'décimal en base' => ['36000.00', "36\u{00A0}000,00\u{00A0}DA"],
        'millions' => [1234567.8, "1\u{00A0}234\u{00A0}567,80\u{00A0}DA"],
        'zéro' => ['0.00', "0,00\u{00A0}DA"],
    ]);

    it('writes integers with non-breaking spaces', function () {
        expect(Formats::integer(1234567))->toBe("1\u{00A0}234\u{00A0}567")->and(Formats::integer(0))->toBe('0');
    });

    it('writes a VAT rate without useless zeros', function (string $rate, string $expected) {
        expect(Formats::rate($rate))->toBe($expected);
    })->with([['19.00', '19'], ['9.00', '9'], ['9.50', '9,5'], ['0.00', '0'], ['10.00', '10']]);
});

describe('dates', function () {
    it('writes a calendar date as JJ/MM/AAAA and « — » without date', function () {
        expect(Formats::date(CarbonImmutable::parse('2026-10-08', 'Africa/Algiers')))->toBe('08/10/2026')->and(Formats::date(null))->toBe('—');
    });

    it('writes an instant in Algiers time', function () {
        // 23:30 UTC la veille est 00:30 le lendemain à Alger (UTC + 1).
        expect(Formats::dateTime(CarbonImmutable::parse('2026-10-07 23:30:00', 'UTC')))->toBe('08/10/2026 00:30')
            ->and(Formats::dateTime(null))->toBe('—');
    });
});

it('keeps the indicative amounts notice (RG-P05)', function () {
    expect(Formats::amountNotice())->toBe('Montants indicatifs : la facture ERP fait foi.');
});
