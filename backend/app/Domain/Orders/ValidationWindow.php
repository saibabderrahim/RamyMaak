<?php

namespace App\Domain\Orders;

use App\Domain\Days\CutoffKind;
use App\Domain\Settings\Settings;
use App\Models\BusinessDay;
use App\Models\RegionDay;
use Carbon\CarbonInterface;

/**
 * Fenêtre de validation (RG-V08, §4.3, I-71) : une seule règle pour le
 * détail régional, « Ma journée », le suivi ADV et la validation elle-même.
 * L'heure limite est celle de la région (prolongation de l'ADV comprise),
 * sinon celle de la journée ; non bloquante par défaut. L'heure du serveur
 * fait foi (D-10).
 */
final class ValidationWindow
{
    public function __construct(private readonly Settings $settings) {}

    /** Paramètre `cutoffs.validation_blocking` : à lire avant toute transaction (I-67). */
    public function blocking(): bool
    {
        return (bool) $this->settings->get('cutoffs.validation_blocking');
    }

    /**
     * `$regionDay` : journée régionale de la commande (région figée), null si
     * elle n'existe pas (heure de la journée). `$blocking` : valeur lue avant
     * la transaction, sinon relue ici.
     */
    public function state(BusinessDay $day, ?RegionDay $regionDay, ?bool $blocking = null, ?CarbonInterface $now = null): ValidationState
    {
        $regionDay?->setRelation('businessDay', $day);
        $cutoff = ($regionDay?->effectiveCutoff(CutoffKind::Validation) ?? $day->cutoff(CutoffKind::Validation))->toImmutable()->utc();

        return new ValidationState(
            $cutoff,
            $regionDay?->isExtended(CutoffKind::Validation) ?? false,
            $blocking ?? $this->blocking(),
            ($now ?? now())->greaterThanOrEqualTo($cutoff),
        );
    }
}
