<?php

namespace App\Domain\Auth;

/**
 * Mot de passe temporaire à usage unique (EF-AUTH-05, EF-ADM-02) : lisible
 * pour être recopié depuis une fiche d'accès (pas de 0/O, 1/l/I).
 */
final class TemporaryPassword
{
    private const LETTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    private const LENGTH = 10;

    public static function generate(): string
    {
        $alphabet = self::LETTERS.self::DIGITS;

        $chars = [
            self::pick(self::LETTERS),
            self::pick(self::DIGITS),
        ];
        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($alphabet);
        }

        // Mélange de Fisher-Yates avec une source cryptographique.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
