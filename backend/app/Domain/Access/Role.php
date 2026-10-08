<?php

namespace App\Domain\Access;

/**
 * Rôles de la plateforme (§3.1). Un utilisateur a exactement un rôle.
 */
enum Role: string
{
    case Admin = 'admin';
    case Adv = 'adv';
    case Regional = 'regional';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Adv => 'ADV',
            self::Regional => 'Régional',
            self::Client => 'Client',
        };
    }

    /**
     * Personnel du back-office : sessions courtes, mot de passe renforcé,
     * pas de « Rester connecté » (EF-AUTH-03, EF-AUTH-06).
     */
    public function isBackOffice(): bool
    {
        return $this === self::Admin || $this === self::Adv;
    }

    /**
     * Permissions accordées par défaut (matrice des droits, §3.2). Elles sont
     * semées une seule fois : un Admin peut ensuite ajuster un rôle sans
     * modifier le code.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::UserManage,
                Permission::UserResetClientPassword,
                Permission::RegionView,
                Permission::RegionManage,
                Permission::ClientView,
                Permission::ClientManage,
                Permission::ClientBlock,
                Permission::ProductView,
                Permission::ProductManage,
                Permission::QuotaView,
                Permission::LimitView,
                Permission::OrderView,
                Permission::DayExtendCutoff,
                Permission::DashboardView,
                Permission::SettingsManage,
                Permission::AuditView,
            ],
            self::Adv => [
                Permission::RegionView,
                Permission::ClientView,
                Permission::ProductView,
                Permission::QuotaView,
                Permission::QuotaEdit,
                Permission::QuotaPublish,
                Permission::LimitView,
                Permission::OrderView,
                Permission::OrderProcess,
                Permission::OrderCancelValidated,
                Permission::DayManage,
                Permission::DayExtendCutoff,
                Permission::DashboardView,
            ],
            self::Regional => [
                Permission::UserResetClientPassword,
                Permission::RegionView,
                Permission::ClientView,
                Permission::ProductView,
                Permission::QuotaView,
                Permission::LimitView,
                Permission::LimitEdit,
                Permission::LimitPublish,
                Permission::OrderView,
                Permission::OrderValidate,
                Permission::DashboardView,
            ],
            self::Client => [
                Permission::ProductView,
                Permission::LimitView,
                Permission::OrderView,
                Permission::OrderCreate,
            ],
        };
    }
}
