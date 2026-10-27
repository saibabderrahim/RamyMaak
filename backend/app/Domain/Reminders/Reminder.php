<?php

namespace App\Domain\Reminders;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;

/**
 * Rappels et alerte du planificateur (contrat du lot 8, §3) : heure limite
 * visée et fenêtre d'envoi [début ; fin[ autour de l'heure limite
 * **effective** de la région (RG-A05).
 *
 * - N-10 : [répartition − 30 min ; répartition[ ;
 * - N-11 : [commande − 1 h ; commande[ ;
 * - N-12 : [validation − 30 min ; validation[ ;
 * - N-13 : [répartition ; clôture[.
 *
 * Délais en configuration (`ramymaak.reminders`, I-108).
 */
enum Reminder: string
{
    case Distribution = 'DISTRIBUTION_REMINDER';
    case Ordering = 'ORDERING_REMINDER';
    case Validation = 'VALIDATION_REMINDER';
    case DistributionLate = 'DISTRIBUTION_LATE';

    public function type(): NotificationType
    {
        return NotificationType::from($this->value);
    }

    public function cutoffKind(): CutoffKind
    {
        return match ($this) {
            self::Distribution, self::DistributionLate => CutoffKind::Distribution,
            self::Ordering => CutoffKind::Ordering,
            self::Validation => CutoffKind::Validation,
        };
    }

    /**
     * Fenêtre d'envoi [début ; fin[ (UTC) d'une journée régionale chargée
     * avec sa journée.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(RegionDay $regionDay): array
    {
        $cutoff = $regionDay->effectiveCutoff($this->cutoffKind())->toImmutable()->utc();

        return match ($this) {
            // Alerte : de l'heure limite à la clôture (I-107).
            self::DistributionLate => [$cutoff, $regionDay->businessDay->cutoff(CutoffKind::Closing)->toImmutable()->utc()],
            default => [$cutoff->subMinutes($this->leadMinutes()), $cutoff],
        };
    }

    /** Minutes avant l'heure limite où le rappel devient dû (`ramymaak.reminders`). */
    public function leadMinutes(): int
    {
        return match ($this) {
            self::Distribution => (int) config('ramymaak.reminders.distribution'),
            self::Ordering => (int) config('ramymaak.reminders.ordering'),
            self::Validation => (int) config('ramymaak.reminders.validation'),
            self::DistributionLate => 0,
        };
    }

    /** Rappel coupable par les préférences (EF-NOT-04) ; N-13 ne l'est pas. */
    public function canBeMuted(): bool
    {
        return $this->type()->isReminder();
    }
}
