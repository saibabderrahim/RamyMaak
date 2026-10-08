<?php

namespace App\Domain\Referentials;

use App\Domain\Auth\SessionRevoker;
use App\Models\Client;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Création et modification des clients, garantes des règles du référentiel :
 *
 * - le code client (code ERP) ne change pas après la création ;
 * - un changement de région prend effet à la journée suivante (RG-S04) ;
 * - un blocage exige un motif (EF-CLI-04) ;
 * - un client inactif voit ses comptes désactivés et leurs sessions
 *   révoquées (§3.5).
 *
 * Utilisé par l'API et par l'import Excel.
 */
final class ClientRegistry
{
    public function __construct(private readonly SessionRevoker $revoker) {}

    /**
     * @param  array<string, mixed>  $attributes  champs de la fiche, region_id,
     *                                            status et status_reason facultatifs
     */
    public function create(array $attributes, ?User $by): Client
    {
        return DB::transaction(function () use ($attributes, $by) {
            $client = new Client;
            $client->fill(Arr::only($attributes, $client->getFillable()));
            $client->region_id = (int) $attributes['region_id'];

            $status = $this->statusFrom($attributes) ?? ClientStatus::Active;
            $this->assertReason($status, $attributes['status_reason'] ?? null);
            $client->status = $status;
            if ($status !== ClientStatus::Active) {
                $client->status_reason = $attributes['status_reason'] ?? null;
                $client->status_changed_at = now();
                $client->status_changed_by = $by?->getKey();
            }

            $client->save();

            return $client;
        });
    }

    /**
     * Mise à jour partielle : seuls les champs présents sont modifiés.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Client $client, array $attributes, ?User $by): Client
    {
        return DB::transaction(function () use ($client, $attributes, $by) {
            $client->fill(Arr::except(Arr::only($attributes, $client->getFillable()), ['code']));

            if (array_key_exists('region_id', $attributes) && $attributes['region_id'] !== null) {
                $this->scheduleRegionChange($client, (int) $attributes['region_id']);
            }

            $client->save();

            $status = $this->statusFrom($attributes);
            if ($status !== null) {
                $this->changeStatus($client, $status, $attributes['status_reason'] ?? null, $by);
            }

            return $client->refresh();
        });
    }

    /**
     * Programme le changement de région pour la journée suivante (RG-S04) ;
     * revenir à la région actuelle annule le changement en attente. Le
     * client n'est pas enregistré.
     */
    public function scheduleRegionChange(Client $client, int $regionId, ?CarbonInterface $today = null): void
    {
        $today ??= BusinessClock::today();

        // Un changement déjà échu devient la région actuelle.
        if ($client->next_region_id !== null && $client->region_change_on !== null
            && $client->region_change_on->format('Y-m-d') <= $today->format('Y-m-d')) {
            $client->region_id = $client->next_region_id;
            $client->next_region_id = null;
            $client->region_change_on = null;
        }

        if ($regionId === $client->region_id) {
            $client->next_region_id = null;
            $client->region_change_on = null;

            return;
        }

        $client->next_region_id = $regionId;
        $client->region_change_on = $today->addDay()->startOfDay();
    }

    public function changeStatus(Client $client, ClientStatus $status, ?string $reason, ?User $by): void
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $this->assertReason($status, $reason);

        if ($client->status === $status && $client->status_reason === $reason) {
            return;
        }

        $previous = $client->status;

        DB::transaction(function () use ($client, $status, $reason, $by, $previous) {
            $client->forceFill([
                'status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $by?->getKey(),
            ])->save();

            if ($status === ClientStatus::Inactive && $previous !== ClientStatus::Inactive) {
                $this->deactivateAccounts($client);
            }
        });
    }

    /**
     * Applique les changements de région arrivés à échéance (tâche
     * planifiée, rejouable sans double effet).
     */
    public function applyDueRegionChanges(?CarbonInterface $today = null): int
    {
        $today ??= BusinessClock::today();
        $applied = 0;

        Client::query()
            ->whereNotNull('next_region_id')
            ->where('region_change_on', '<=', $today->format('Y-m-d'))
            ->chunkById(200, function ($clients) use (&$applied) {
                foreach ($clients as $client) {
                    $client->forceFill([
                        'region_id' => $client->next_region_id,
                        'next_region_id' => null,
                        'region_change_on' => null,
                    ])->save();
                    $applied++;
                }
            });

        return $applied;
    }

    private function deactivateAccounts(Client $client): void
    {
        foreach ($client->accounts()->where('is_active', true)->get() as $account) {
            $account->forceFill(['is_active' => false])->save();
            $this->revoker->revokeAll($account);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function statusFrom(array $attributes): ?ClientStatus
    {
        $status = $attributes['status'] ?? null;

        return match (true) {
            $status instanceof ClientStatus => $status,
            is_string($status) && $status !== '' => ClientStatus::from($status),
            default => null,
        };
    }

    private function assertReason(ClientStatus $status, ?string $reason): void
    {
        if ($status === ClientStatus::Blocked && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('Un motif est obligatoire pour bloquer un client.');
        }
    }
}
