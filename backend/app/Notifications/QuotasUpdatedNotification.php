<?php

namespace App\Notifications;

use App\Models\RegionDay;

/**
 * N-02 (§7.8) : quotas de la région modifiés après publication (RG-Q04).
 * « Quotas du 08/10 modifiés — région Centre » / « L'ADV a modifié 3 quotas. »
 * (nombre de produits changés par la publication).
 */
final class QuotasUpdatedNotification extends RegionDayNotification
{
    public function __construct(RegionDay $regionDay, public readonly int $version, public readonly int $changes)
    {
        parent::__construct($regionDay);
    }

    public function type(): NotificationType
    {
        return NotificationType::QuotasUpdated;
    }

    public function title(): string
    {
        return __('notifications.quotas_updated.title', ['day' => $this->dayLabel(), 'region' => $this->regionName]);
    }

    public function body(): string
    {
        return trans_choice('notifications.quotas_updated.body', $this->changes, ['count' => $this->changes]);
    }

    protected function uniqueKey(): string
    {
        return $this->keyPrefix().'|version:'.$this->version;
    }
}
