<?php

namespace App\Notifications;

/**
 * N-04 (§7.8, RG-B07) : bornes du client modifiées après la première
 * publication de la répartition ; seuls les clients impactés la reçoivent.
 * « Quotas du 08/10 mis à jour » / « Vos quotas du jour ont été mis à jour. »
 */
final class LimitsUpdatedNotification extends ClientDayNotification
{
    public function type(): NotificationType
    {
        return NotificationType::LimitsUpdated;
    }

    public function title(): string
    {
        return __('notifications.limits_updated.title', ['day' => $this->dayLabel()]);
    }

    public function body(): string
    {
        return __('notifications.limits_updated.body');
    }
}
