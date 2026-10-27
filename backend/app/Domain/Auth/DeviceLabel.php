<?php

namespace App\Domain\Auth;

/**
 * Libellé lisible d'un appareil connecté, tiré de l'en-tête User-Agent
 * (contrat du lot 8, §5, I-110) : « {navigateur} sur {système} », par
 * exemple « Chrome sur Android ». Repli : « Navigateur inconnu ».
 *
 * Navigateurs : Chrome, Edge, Firefox, Safari, Samsung Internet, Opera ;
 * systèmes : Android, iPhone, iPad, Windows, macOS, Linux. Un iPad en mode
 * « site pour ordinateur » (iPadOS 13 et suivants) se présente comme un Mac :
 * il est lu « Safari sur macOS ».
 */
final class DeviceLabel
{
    public const UNKNOWN = 'Navigateur inconnu';

    /**
     * Jetons des navigateurs, dans l'ordre de lecture : ceux qui reprennent
     * le jeton « Chrome » ou « Safari » (Samsung Internet, Edge, Opera)
     * passent avant Chrome, Chrome avant Safari.
     *
     * @var array<string, list<string>>
     */
    private const BROWSERS = [
        'Samsung Internet' => ['SamsungBrowser/'],
        'Edge' => ['Edg/', 'EdgA/', 'EdgiOS/', 'Edge/'],
        'Opera' => ['OPR/', 'OPT/', 'OPiOS/', 'Opera'],
        'Firefox' => ['Firefox/', 'FxiOS/'],
        'Chrome' => ['Chrome/', 'CriOS/'],
        'Safari' => ['Safari/'],
    ];

    /**
     * Jetons des systèmes, dans l'ordre de lecture : iPhone et iPad avant
     * macOS (« like Mac OS X »), Android avant Linux.
     *
     * @var array<string, list<string>>
     */
    private const SYSTEMS = [
        'iPad' => ['iPad'],
        'iPhone' => ['iPhone', 'iPod'],
        'Android' => ['Android'],
        'Windows' => ['Windows'],
        'macOS' => ['Macintosh', 'Mac OS X'],
        'Linux' => ['Linux'],
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return self::UNKNOWN;
        }

        $system = self::match(self::SYSTEMS, $userAgent);
        $browser = self::match(self::BROWSERS, $userAgent);
        // Application installée sur l'écran d'accueil d'un iPhone ou d'un iPad :
        // WebKit sans jeton « Safari/ ».
        if ($browser === null && in_array($system, ['iPhone', 'iPad'], true) && str_contains($userAgent, 'AppleWebKit')) {
            $browser = 'Safari';
        }
        $browser ??= self::UNKNOWN;

        return $system === null ? $browser : $browser.' sur '.$system;
    }

    /**
     * @param  array<string, list<string>>  $candidates
     */
    private static function match(array $candidates, string $userAgent): ?string
    {
        foreach ($candidates as $label => $tokens) {
            foreach ($tokens as $token) {
                if (str_contains($userAgent, $token)) {
                    return $label;
                }
            }
        }

        return null;
    }
}
