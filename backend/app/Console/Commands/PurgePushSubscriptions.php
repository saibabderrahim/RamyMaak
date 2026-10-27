<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\PushThrottle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purge nocturne des push (§9.7, contrat du lot 8 §2.4) : abonnements en
 * échec répété (`ramymaak.push.max_failures` échecs consécutifs ou plus),
 * abonnements inutilisés depuis `ramymaak.push.unused_days` jours (dernier
 * envoi réussi ou dernier enregistrement par la PWA, sinon création), états
 * d'anti-saturation inchangés depuis 7 jours. Rejouable.
 */
class PurgePushSubscriptions extends Command
{
    /** Jours au-delà desquels un état d'anti-saturation est oublié. */
    private const THROTTLE_DAYS = 7;

    protected $signature = 'ramymaak:purge-push-subscriptions';

    protected $description = 'Supprime les abonnements push en échec ou inutilisés et les anciens états d\'anti-saturation';

    public function handle(): int
    {
        $failed = PushSubscription::query()
            ->where('failures', '>=', max(1, (int) config('ramymaak.push.max_failures')))
            ->delete();

        $unusedSince = now()->subDays((int) config('ramymaak.push.unused_days'));
        $unused = PushSubscription::query()
            ->where(fn (Builder $query) => $query
                ->where('last_used_at', '<', $unusedSince)
                ->orWhere(fn (Builder $never) => $never->whereNull('last_used_at')->where('created_at', '<', $unusedSince)))
            ->delete();

        $throttles = PushThrottle::query()
            ->where('updated_at', '<', now()->subDays(self::THROTTLE_DAYS))
            ->delete();

        $this->info("{$failed} abonnement(s) en échec, {$unused} abonnement(s) inutilisé(s), {$throttles} état(s) d'anti-saturation supprimé(s).");

        return self::SUCCESS;
    }
}
