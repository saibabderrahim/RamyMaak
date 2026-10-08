<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Journalisation des référentiels (§12) : valeurs avant / après de chaque
 * champ modifiable, dans le journal `referentials`.
 */
trait LogsReferentialChanges
{
    use LogsActivity;

    /**
     * Libellés des événements, ex. ['created' => 'Client créé', …].
     *
     * @return array{created: string, updated: string, deleted: string}
     */
    abstract protected static function auditDescriptions(): array;

    public function getActivitylogOptions(): LogOptions
    {
        $descriptions = static::auditDescriptions();

        return LogOptions::defaults()
            ->useLogName('referentials')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => $descriptions[$event] ?? $event);
    }
}
