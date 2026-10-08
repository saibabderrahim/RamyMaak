<?php

namespace App\Domain\Auth;

use App\Domain\Access\Role;
use App\Domain\Settings\Settings;
use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * Politique de mot de passe (EF-AUTH-03) : longueur minimale selon le rôle,
 * lettres et chiffres obligatoires, refus des mots de passe courants et de
 * l'identifiant.
 */
final class PasswordPolicy
{
    private const COMMON_PASSWORDS_FILE = 'security/common-passwords.txt';

    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function __construct(private readonly Settings $settings) {}

    public function minLength(?Role $role): int
    {
        return $role?->isBackOffice()
            ? $this->settings->int('passwords.min_length_staff')
            : $this->settings->int('passwords.min_length');
    }

    /**
     * @return list<mixed>
     */
    public function rules(?Role $role, ?string $username = null): array
    {
        return [
            'required',
            'string',
            'max:128',
            Password::min($this->minLength($role))->letters()->numbers(),
            function (string $attribute, mixed $value, Closure $fail) use ($username): void {
                $normalized = mb_strtolower((string) $value);

                if (self::isCommon($normalized)) {
                    $fail(__('passwords.common'));
                } elseif ($username !== null && $username !== '' && str_contains($normalized, mb_strtolower($username))) {
                    $fail(__('passwords.contains_username'));
                }
            },
        ];
    }

    public static function isCommon(string $password): bool
    {
        if (self::$common === null) {
            $lines = file(resource_path(self::COMMON_PASSWORDS_FILE), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::$common = array_fill_keys(array_map(fn (string $l) => mb_strtolower(trim($l)), $lines), true);
        }

        return isset(self::$common[mb_strtolower($password)]);
    }
}
