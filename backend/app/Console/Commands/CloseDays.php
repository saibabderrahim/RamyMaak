<?php

namespace App\Console\Commands;

use App\Domain\Days\DayCalendar;
use Illuminate\Console\Command;

/**
 * Clôture les journées arrivées à leur heure de clôture (§4.4, §9.7) :
 * journée et journées régionales clôturées, quotas figés. Lancée chaque
 * minute par le planificateur ; rejouable sans double effet.
 */
class CloseDays extends Command
{
    protected $signature = 'ramymaak:close-days';

    protected $description = 'Clôture les journées dont l\'heure de clôture est passée';

    public function handle(DayCalendar $calendar): int
    {
        $closed = $calendar->closeDue();
        $this->info("{$closed} journée(s) clôturée(s).");

        return self::SUCCESS;
    }
}
