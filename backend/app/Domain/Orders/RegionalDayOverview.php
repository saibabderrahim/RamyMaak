<?php

namespace App\Domain\Orders;

use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * « Ma journée » du régional (EF-REG-01, EF-KPI-04 ; contrat du lot 6, §3) :
 * pour chaque région de son périmètre ce jour-là, statut, heures limites
 * (validation comprise, prolongation de la région comprise), commandes par
 * onglet, plus ancienne commande à valider et clients (notifiés, ayant
 * commandé, sans commande), puis la liste des clients sans commande avec
 * leurs téléphones. Lecture sans verrou ni écriture, hors création des
 * journées régionales manquantes (comme « Quotas du jour »).
 *
 * - **notifiés** : destinataires de N-03 une fois la répartition envoyée
 *   (LimitBook::openingRecipients), sinon 0 ;
 * - **ont commandé** : clients distincts ayant une commande comptée
 *   (Envoyée, Validée et suivantes), dans la région figée à l'envoi ;
 * - **sans commande** : notifiés moins ceux qui ont commandé (I-88) ; une
 *   commande seulement rejetée, annulée ou expirée ne compte pas.
 *
 * Nombre de requêtes indépendant du nombre de commandes : une requête
 * groupée pour les compteurs, une pour les clients ayant commandé, une par
 * région pour ses destinataires, une pour la fiche des clients sans commande.
 */
final class RegionalDayOverview
{
    /** Onglets de « Commandes reçues » (comme RegionalOrderController, lot 5). */
    private const TABS = [
        'SUBMITTED' => [OrderStatus::Submitted],
        'VALIDATED' => [OrderStatus::Validated, OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped, OrderStatus::Delivered],
        'REJECTED' => [OrderStatus::Rejected],
        'CLOSED' => [OrderStatus::Cancelled, OrderStatus::Expired],
    ];

    public function __construct(
        private readonly ValidationWindow $window,
        private readonly LimitBook $limits,
        private readonly DayCalendar $calendar,
    ) {}

    /**
     * @param  list<int>  $regionIds  régions du périmètre de la journée (éventuellement réduites à la région filtrée)
     * @return array<string, mixed>
     */
    public function read(CarbonImmutable $date, array $regionIds): array
    {
        $blocking = $this->window->blocking();
        $day = BusinessDay::forDay($date->format('Y-m-d'));
        if ($day === null) {
            return [
                'day' => $date->format('Y-m-d'),
                'opened' => false,
                'status' => null,
                'cutoffs' => null,
                'validation_blocking' => $blocking,
                'regions' => [],
                'clients_without_order' => [],
            ];
        }

        // Une région créée depuis l'ouverture apparaît en attente des quotas.
        if ($day->isOpen()) {
            $this->calendar->syncRegions($day);
        }
        /** @var Collection<int, RegionDay> $regionDays */
        $regionDays = RegionDay::query()
            ->where('business_day_id', $day->id)
            ->whereIn('region_id', $regionIds)
            ->whereHas('region', fn ($query) => $query->where('is_active', true))
            ->with('region')
            ->get()
            ->each(fn (RegionDay $regionDay) => $regionDay->setRelation('businessDay', $day))
            ->sortBy(fn (RegionDay $regionDay) => $regionDay->region->code)
            ->values();

        $orders = $this->orderCounts($day, $regionDays->pluck('region_id')->all());
        $ordered = $this->orderedClients($day, $regionDays->pluck('region_id')->all());

        $regions = [];
        $withoutOrder = [];
        foreach ($regionDays as $regionDay) {
            $regionId = $regionDay->region_id;
            $notified = $this->notified($regionDay);
            $missing = self::missing($notified, $ordered[$regionId] ?? []);
            $withoutOrder[$regionId] = $missing;

            $regions[] = $this->region($regionDay, $blocking, $orders[$regionId] ?? [], [
                'notified' => count($notified),
                'ordered' => count($ordered[$regionId] ?? []),
                'without_order' => count($missing),
            ]);
        }

        return [
            'day' => $day->dayKey(),
            'opened' => true,
            'status' => $day->status->value,
            'cutoffs' => $this->cutoffs($day),
            'validation_blocking' => $blocking,
            'regions' => $regions,
            'clients_without_order' => $this->clientsWithoutOrder($regionDays, $withoutOrder),
        ];
    }

    /**
     * Clients sans commande d'une journée régionale (I-88), même calcul que
     * « Ma journée » : destinataires du rappel N-11 (contrat du lot 8, §3).
     * Journée régionale chargée avec sa journée.
     *
     * @return list<int>
     */
    public function clientsWithoutOrder(RegionDay $regionDay): array
    {
        $ordered = $this->orderedClients($regionDay->businessDay, [$regionDay->region_id]);

        return self::missing($this->notified($regionDay), $ordered[$regionDay->region_id] ?? []);
    }

    /**
     * Notifiés : destinataires de N-03 une fois la répartition envoyée, sinon aucun.
     *
     * @return list<int>
     */
    private function notified(RegionDay $regionDay): array
    {
        return $regionDay->isDistributionPublished() ? $this->limits->openingRecipients($regionDay) : [];
    }

    /**
     * Sans commande : notifiés moins ceux qui ont une commande comptée (I-88).
     *
     * @param  list<int>  $notified
     * @param  list<int>  $ordered
     * @return list<int>
     */
    private static function missing(array $notified, array $ordered): array
    {
        return array_values(array_diff($notified, $ordered));
    }

    /**
     * @param  array{counts?: array<string, int>, oldest?: ?string}  $orders
     * @param  array{notified: int, ordered: int, without_order: int}  $clients
     * @return array<string, mixed>
     */
    private function region(RegionDay $regionDay, bool $blocking, array $orders, array $clients): array
    {
        $validation = $this->window->state($regionDay->businessDay, $regionDay, $blocking);
        $region = $regionDay->region;
        $local = fn (CutoffKind $kind): string => BusinessClock::toLocal($regionDay->effectiveCutoff($kind))->format('H:i');

        return [
            'region' => ['id' => $region->id, 'code' => $region->code, 'name' => $region->name],
            'status' => $regionDay->status->value,
            'status_label' => $regionDay->status->label(),
            'quota_published_at' => $regionDay->quota_published_at?->toIso8601String(),
            'distribution_published_at' => $regionDay->distribution_published_at?->toIso8601String(),
            'cutoffs' => [
                'distribution' => $local(CutoffKind::Distribution),
                'ordering' => $local(CutoffKind::Ordering),
                'validation' => $validation->cutoff(),
            ],
            'extended' => [
                'distribution' => $regionDay->isExtended(CutoffKind::Distribution),
                'ordering' => $regionDay->isExtended(CutoffKind::Ordering),
                'validation' => $validation->extended,
            ],
            'orders' => $orders['counts'] ?? array_fill_keys(array_keys(self::TABS), 0),
            'oldest_submitted_at' => $orders['oldest'] ?? null,
            'clients' => $clients,
        ];
    }

    /**
     * Heures limites de la journée, « HH:MM » dans le fuseau métier.
     *
     * @return array<string, string>
     */
    private function cutoffs(BusinessDay $day): array
    {
        $cutoffs = [];
        foreach (CutoffKind::chronological() as $kind) {
            $cutoffs[strtolower($kind->value)] = BusinessClock::toLocal($day->cutoff($kind))->format('H:i');
        }

        return $cutoffs;
    }

    /**
     * Commandes de chaque région par onglet et plus ancienne commande envoyée,
     * en une requête groupée.
     *
     * @param  list<int>  $regionIds
     * @return array<int, array{counts: array<string, int>, oldest: ?string}> région → compteurs
     */
    private function orderCounts(BusinessDay $day, array $regionIds): array
    {
        $rows = Order::query()
            ->toBase()
            ->where('business_day_id', $day->id)
            ->whereIn('region_id', $regionIds)
            ->select('region_id', 'status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->selectRaw('MIN(submitted_at) AS oldest')
            ->groupBy('region_id', 'status')
            ->get();

        $perRegion = [];
        foreach ($rows as $row) {
            $region = (int) $row->region_id;
            $perRegion[$region] ??= ['counts' => array_fill_keys(array_keys(self::TABS), 0), 'oldest' => null];
            foreach (self::TABS as $tab => $statuses) {
                if (in_array($row->status, OrderStatus::values($statuses), true)) {
                    $perRegion[$region]['counts'][$tab] += (int) $row->aggregate;
                }
            }
            if ($row->status === OrderStatus::Submitted->value) {
                // Date enregistrée en UTC.
                $perRegion[$region]['oldest'] = CarbonImmutable::parse($row->oldest, 'UTC')->toIso8601String();
            }
        }

        return $perRegion;
    }

    /**
     * Clients ayant une commande comptée dans chaque région (région figée à l'envoi).
     *
     * @param  list<int>  $regionIds
     * @return array<int, list<int>> région → clients
     */
    private function orderedClients(BusinessDay $day, array $regionIds): array
    {
        $ordered = [];
        $rows = Order::query()
            ->toBase()
            ->where('business_day_id', $day->id)
            ->whereIn('region_id', $regionIds)
            ->whereIn('status', OrderStatus::values(OrderStatus::counted()))
            ->select('region_id', 'client_id')
            ->groupBy('region_id', 'client_id')
            ->get();
        foreach ($rows as $row) {
            $ordered[(int) $row->region_id][] = (int) $row->client_id;
        }

        return $ordered;
    }

    /**
     * Fiche des clients sans commande, par région (code) puis par code client.
     *
     * @param  Collection<int, RegionDay>  $regionDays
     * @param  array<int, list<int>>  $withoutOrder  région → clients sans commande
     * @return list<array<string, mixed>>
     */
    private function clientsWithoutOrder(Collection $regionDays, array $withoutOrder): array
    {
        $ids = array_merge(...array_values($withoutOrder));
        if ($ids === []) {
            return [];
        }
        $clients = Client::query()->whereIn('id', $ids)->orderBy('code')->get(['id', 'code', 'name', 'status', 'phone', 'phone2']);

        $list = [];
        foreach ($regionDays as $regionDay) {
            $missing = array_flip($withoutOrder[$regionDay->region_id] ?? []);
            foreach ($clients as $client) {
                if (isset($missing[$client->id])) {
                    $list[] = [
                        'id' => $client->id,
                        'code' => $client->code,
                        'name' => $client->name,
                        'status' => $client->status->value,
                        'region_id' => $regionDay->region_id,
                        'phone' => $client->phone,
                        'phone2' => $client->phone2,
                    ];
                }
            }
        }

        return $list;
    }
}
