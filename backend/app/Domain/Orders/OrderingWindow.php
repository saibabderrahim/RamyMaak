<?php

namespace App\Domain\Orders;

use App\Domain\Days\CutoffKind;
use App\Domain\Days\RegionDayStatus;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fenêtre de commande (RG-C01, §4.3) : une seule règle pour l'accueil du
 * client, l'envoi, la modification et l'annulation. L'heure du serveur
 * fait foi (D-10) ; l'heure limite est celle de la région, prolongation
 * comprise.
 */
final class OrderingWindow
{
    /**
     * État pour un client et une journée (« AAAA-MM-JJ ») ; `$lock` : verrou
     * partagé sur la journée (envoi), pour que la clôture attende la fin
     * de l'envoi ou que l'envoi voie la journée clôturée.
     */
    public function state(Client $client, CarbonImmutable $day, bool $lock = false, ?CarbonInterface $now = null): OrderingState
    {
        $businessDay = BusinessDay::query()
            ->where('day', $day->format('Y-m-d'))
            ->when($lock, fn ($query) => $query->sharedLock())
            ->first();

        return $this->evaluate($client, $day, $businessDay, $now);
    }

    /**
     * État pour la région figée d'une commande existante (modification,
     * annulation, accueil).
     */
    public function forRegion(BusinessDay $businessDay, int $regionId, ?CarbonInterface $now = null): OrderingState
    {
        $regionDay = RegionDay::query()
            ->where('business_day_id', $businessDay->id)
            ->where('region_id', $regionId)
            ->first();

        return $this->build($businessDay->dayKey(), $businessDay, $regionDay, $regionId, $now);
    }

    /**
     * Journée proposée au client (I-42) : la première journée ouverte à la
     * commande à partir d'aujourd'hui (aujourd'hui, ou demain si la
     * répartition est déjà envoyée), sinon la journée d'aujourd'hui, sinon
     * la prochaine journée ouverte, sinon aujourd'hui (en attente).
     */
    public function defaultDay(Client $client, ?CarbonInterface $now = null): CarbonImmutable
    {
        $today = BusinessClock::today();
        $days = BusinessDay::query()
            ->where('day', '>=', $today->format('Y-m-d'))
            ->orderBy('day')
            ->limit(3)
            ->get();

        foreach ($days as $businessDay) {
            if ($this->evaluate($client, $businessDay->day, $businessDay, $now)->isOpen()) {
                return $businessDay->day;
            }
        }
        $first = $days->first();

        return $first?->day ?? $today;
    }

    /**
     * @throws ApiException DAY_NOT_OPEN (journée non ouverte, quotas ou répartition non publiés, journée clôturée),
     *                      CUTOFF_PASSED (heure limite de commande atteinte)
     */
    public function assertOpen(OrderingState $state): void
    {
        match ($state->status) {
            ClientDayStatus::OrderingOpen => null,
            ClientDayStatus::OrderingClosed => throw new ApiException(ErrorCode::CutoffPassed, __('orders.cutoff_passed', [
                'time' => $state->cutoffAt === null ? '' : BusinessClock::toLocal($state->cutoffAt)->format('H:i'),
            ])),
            ClientDayStatus::DayClosed => throw new ApiException(ErrorCode::DayNotOpen, __('orders.day_closed')),
            ClientDayStatus::WaitingQuotas => throw new ApiException(ErrorCode::DayNotOpen, __('orders.not_open')),
        };
    }

    private function evaluate(Client $client, CarbonImmutable $day, ?BusinessDay $businessDay, ?CarbonInterface $now): OrderingState
    {
        $regionId = $client->effectiveRegionId($day);
        if ($businessDay === null) {
            return new OrderingState($day->format('Y-m-d'), null, null, $regionId, ClientDayStatus::WaitingQuotas, null, false);
        }
        $regionDay = RegionDay::query()
            ->where('business_day_id', $businessDay->id)
            ->where('region_id', $regionId)
            ->first();

        return $this->build($businessDay->dayKey(), $businessDay, $regionDay, $regionId, $now);
    }

    private function build(string $day, BusinessDay $businessDay, ?RegionDay $regionDay, int $regionId, ?CarbonInterface $now): OrderingState
    {
        $now ??= now();
        $regionDay?->setRelation('businessDay', $businessDay);
        $cutoff = $regionDay?->effectiveCutoff(CutoffKind::Ordering) ?? $businessDay->cutoff(CutoffKind::Ordering);
        $extended = $regionDay?->isExtended(CutoffKind::Ordering) ?? false;

        $status = match (true) {
            ! $businessDay->isOpen(), $regionDay?->status === RegionDayStatus::Closed => ClientDayStatus::DayClosed,
            $now->greaterThanOrEqualTo($cutoff) => ClientDayStatus::OrderingClosed,
            $regionDay !== null && $regionDay->isDistributionPublished() => ClientDayStatus::OrderingOpen,
            default => ClientDayStatus::WaitingQuotas,
        };

        return new OrderingState($day, $businessDay, $regionDay, $regionId, $status, $cutoff, $extended);
    }
}
