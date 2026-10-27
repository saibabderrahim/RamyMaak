<?php

namespace App\Domain\Push;

/**
 * Adresses d'abonnement acceptées (I-104) : `https`, sans identifiants ni
 * port autre que 443, sur un hôte de `ramymaak.push.allowed_hosts`
 * (« *.domaine » couvre les sous-domaines, pas le domaine lui-même). Le
 * serveur écrit à ces adresses : c'est une garde contre les requêtes forgées
 * vers le réseau interne.
 */
final class PushEndpoints
{
    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        foreach ((array) config('ramymaak.push.allowed_hosts') as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($pattern !== '' && $host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Longueur en octets d'une valeur base64url (remplissage « = » toléré),
     * ou null si elle n'est pas du base64url.
     */
    public static function base64UrlLength(string $value): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : strlen($decoded);
    }
}
