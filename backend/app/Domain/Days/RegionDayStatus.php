<?php

namespace App\Domain\Days;

/**
 * Journée régionale (§6.1). Les modifications de quotas après publication ne
 * changent pas le statut : elles incrémentent quota_version (N-02).
 */
enum RegionDayStatus: string
{
    case QuotaDraft = 'QUOTA_DRAFT';
    case QuotaPublished = 'QUOTA_PUBLISHED';
    case DistributionDraft = 'DISTRIBUTION_DRAFT';
    case DistributionPublished = 'DISTRIBUTION_PUBLISHED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::QuotaDraft => 'Quotas en brouillon',
            self::QuotaPublished => 'Quotas publiés',
            self::DistributionDraft => 'Répartition en cours',
            self::DistributionPublished => 'Répartition publiée',
            self::Closed => 'Clôturée',
        };
    }
}
