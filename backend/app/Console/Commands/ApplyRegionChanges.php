<?php

namespace App\Console\Commands;

use App\Domain\Referentials\ClientRegistry;
use Illuminate\Console\Command;

/**
 * Applique les changements de région des clients arrivés à échéance
 * (RG-S04). La région effective est de toute façon calculée à la volée ;
 * cette tâche remet les fiches à jour chaque nuit.
 */
class ApplyRegionChanges extends Command
{
    protected $signature = 'ramymaak:apply-region-changes';

    protected $description = 'Applique les changements de région des clients arrivés à échéance';

    public function handle(ClientRegistry $registry): int
    {
        $applied = $registry->applyDueRegionChanges();
        $this->info("{$applied} changement(s) de région appliqué(s).");

        return self::SUCCESS;
    }
}
