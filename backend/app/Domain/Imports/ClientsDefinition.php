<?php

namespace App\Domain\Imports;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Referentials\ClientRegistry;
use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Region;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

/**
 * Import des clients (Annexe B.3, EF-CLI-02), clé code_client. Création et
 * mise à jour par ClientRegistry : code immuable, changement de région à la
 * journée suivante (RG-S04), blocage motivé (EF-CLI-04) — sans motif dans le
 * fichier, « Import Excel du JJ/MM/AAAA ».
 */
final class ClientsDefinition extends ImportDefinition
{
    /** Colonne → [champ de la fiche, longueur maximale]. */
    private const TEXT_COLUMNS = [
        'raison_sociale' => ['name', 255],
        'enseigne' => ['trade_name', 255],
        'adresse' => ['address', 255],
        'wilaya' => ['wilaya', 100],
        'commune' => ['commune', 100],
        'telephone' => ['phone', 30],
        'nif' => ['nif', 30],
        'nis' => ['nis', 30],
        'rc' => ['rc', 30],
        'article_imposition' => ['ai', 30],
    ];

    /** @var array<string, Region> code → région */
    private array $regionsByCode = [];

    /** @var array<int, Region> */
    private array $regionsById = [];

    /** @var array<string, ClientCategory> code ou libellé replié → catégorie */
    private array $categories = [];

    /** @var array<int, ClientCategory> */
    private array $categoriesById = [];

    /** @var array<string, Client> code → client existant */
    private array $clients = [];

    /** @var list<int>|null régions de l'auteur (null : toutes) */
    private ?array $regionIds = null;

    private CarbonImmutable $today;

    public function __construct(
        User $actor,
        private readonly ClientRegistry $registry,
        private readonly Perimeter $perimeter,
    ) {
        parent::__construct($actor);
        $this->today = BusinessClock::today();
    }

    public function columns(): array
    {
        return [
            'code_client', 'raison_sociale', 'enseigne', 'categorie', 'code_region', 'adresse', 'wilaya',
            'commune', 'telephone', 'email', 'nif', 'nis', 'rc', 'article_imposition', 'latitude', 'longitude', 'statut',
        ];
    }

    public function requiredColumns(): array
    {
        return ['code_client', 'raison_sociale', 'code_region'];
    }

    public function keyColumn(): string
    {
        return 'code_client';
    }

    public function key(RowContext $row): ?string
    {
        return $this->code($row, 'code_client');
    }

    public function label(string $key): string
    {
        return $key;
    }

    public function describe(string $key): string
    {
        return __('imports.subjects.client', ['key' => $key]);
    }

    public function preload(array $keys): void
    {
        $this->loadReferences();

        $this->clients = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            foreach (Client::query()->whereIn('code', $chunk)->get() as $client) {
                $this->clients[mb_strtoupper($client->code)] = $client;
            }
        }

        $this->regionIds = $this->perimeter->regionIds($this->actor, $this->today);
    }

    public function check(RowContext $row, string $key): ?RowPlan
    {
        $client = $this->clients[$key] ?? null;
        if ($client !== null && ! $this->perimeter->canAccessClient($this->actor, $client, $this->today)) {
            $row->fail('code_client', __('imports.errors.client_out_of_perimeter', ['value' => $key]));

            return null;
        }

        [$values, $category, $region, $lat, $lng, $status] = $this->read($row);

        if ($client === null) {
            $this->requireForCreation($row, ['raison_sociale', 'code_region'], __('imports.errors.required_to_create', ['subject' => $this->describe($key)]));
        }

        // Latitude et longitude vont ensemble, valeurs actuelles comprises.
        if (! $row->failed('latitude') && ! $row->failed('longitude')) {
            $finalLat = $lat ?? $client?->lat;
            $finalLng = $lng ?? $client?->lng;
            if (($finalLat === null) !== ($finalLng === null)) {
                $row->fail($finalLat === null ? 'latitude' : 'longitude', __('imports.errors.coordinates_pair'));
            }
        }

        $target = $client === null ? null : $this->targetRegionId($client);
        $moves = $region !== null && $region->id !== $target && $this->regionAvailable($row, $region);

        $currentStatus = $client === null ? ClientStatus::Active : $client->status;
        $changesStatus = $status !== null && $status !== $currentStatus;
        if ($changesStatus && ! $this->actor->can(Permission::ClientBlock->value)) {
            $row->fail('statut', __('imports.errors.status_forbidden'));
        }

        if ($row->hasErrors()) {
            return null;
        }

        $changes = [];
        $attributes = $client === null ? ['code' => $key] : [];

        foreach ($values as $field => $value) {
            if ($client === null || $client->getAttribute($field) !== $value) {
                $changes[$field] = ['old' => $client?->getAttribute($field), 'new' => $value];
                $attributes[$field] = $value;
            }
        }

        foreach (['lat' => $lat, 'lng' => $lng] as $field => $value) {
            if ($value !== null && ($client === null || $client->getAttribute($field) !== $value)) {
                $old = $client?->getAttribute($field);
                $changes[$field] = ['old' => $old === null ? null : (float) $old, 'new' => (float) $value];
                $attributes[$field] = $value;
            }
        }

        if ($category !== null && $category->id !== $client?->category_id) {
            $changes['category'] = ['old' => $this->categoryCode($client?->category_id), 'new' => $category->code];
            $attributes['category_id'] = $category->id;
        }

        if ($moves) {
            $changes['region'] = ['old' => $target === null ? null : $this->regionsById[$target]->code ?? null, 'new' => $region->code];
            $attributes['region_id'] = $region->id;
        }

        if ($changesStatus) {
            $reason = $status === ClientStatus::Blocked
                ? __('imports.status_reason', ['date' => $this->today->format('d/m/Y')])
                : null;
            $changes['status'] = ['old' => $client?->status->value, 'new' => $status->value];
            if ($reason !== $client?->status_reason) {
                $changes['status_reason'] = ['old' => $client?->status_reason, 'new' => $reason];
            }
            $attributes['status'] = $status;
            $attributes['status_reason'] = $reason;
        }

        return RowPlan::createOrUpdate($row->line, $key, $key, $client, $changes, $attributes);
    }

    public function checkWithoutKey(RowContext $row): void
    {
        $this->read($row);
    }

    public function apply(RowPlan $plan): void
    {
        if ($plan->record instanceof Client) {
            $this->registry->update($plan->record, $plan->attributes, $this->actor);

            return;
        }

        $this->registry->create($plan->attributes, $this->actor);
    }

    public function templateRows(): iterable
    {
        $this->loadReferences();

        $clients = $this->perimeter->clients($this->actor, $this->today)->orderBy('code');

        foreach ($clients->lazy(500) as $client) {
            yield [
                $client->code,
                $client->name,
                $client->trade_name,
                $this->categoryCode($client->category_id),
                $this->regionsById[$this->targetRegionId($client)]->code ?? null,
                $client->address,
                $client->wilaya,
                $client->commune,
                $client->phone,
                $client->email,
                $client->nif,
                $client->nis,
                $client->rc,
                $client->ai,
                $client->lat === null ? null : (float) $client->lat,
                $client->lng === null ? null : (float) $client->lng,
                self::spreadsheetStatus($client->status),
            ];
        }
    }

    /** Valeur de la colonne « statut » (Annexe B.3). */
    public static function spreadsheetStatus(ClientStatus $status): string
    {
        return match ($status) {
            ClientStatus::Active => 'ACTIF',
            ClientStatus::Blocked => 'BLOQUE',
            ClientStatus::Inactive => 'INACTIF',
        };
    }

    /**
     * Valeurs lues dans les cellules renseignées, indépendamment de la fiche
     * existante : [champs texte et e-mail, catégorie, région, latitude,
     * longitude, statut].
     *
     * @return array{0: array<string, string>, 1: ?ClientCategory, 2: ?Region, 3: ?string, 4: ?string, 5: ?ClientStatus}
     */
    private function read(RowContext $row): array
    {
        $values = [];
        foreach (self::TEXT_COLUMNS as $column => [$field, $max]) {
            $text = $this->text($row, $column, $max);
            if ($text !== null) {
                $values[$field] = $text;
            }
        }

        $email = $this->email($row);
        if ($email !== null) {
            $values['email'] = $email;
        }

        return [
            $values,
            $this->category($row),
            $this->region($row),
            $this->coordinate($row, 'latitude', 90),
            $this->coordinate($row, 'longitude', 180),
            $this->status($row),
        ];
    }

    private function loadReferences(): void
    {
        $this->regionsByCode = [];
        $this->regionsById = [];
        foreach (Region::query()->get() as $region) {
            $this->regionsByCode[mb_strtoupper($region->code)] = $region;
            $this->regionsById[$region->id] = $region;
        }

        // Catégorie désignée par son code ou son libellé ; le code l'emporte.
        $this->categories = [];
        $this->categoriesById = [];
        $categories = ClientCategory::query()->get();
        foreach ($categories as $category) {
            $this->categoriesById[$category->id] = $category;
            $this->categories[self::fold($category->name)] = $category;
        }
        foreach ($categories as $category) {
            $this->categories[self::fold($category->code)] = $category;
        }
    }

    /**
     * Région où sera le client : celle du changement programmé s'il y en a
     * un (RG-S04), sinon sa région actuelle. Un gabarit réimporté tel quel
     * n'annule donc pas un changement en attente.
     */
    private function targetRegionId(Client $client): int
    {
        return $client->next_region_id ?? $client->region_id;
    }

    private function categoryCode(?int $categoryId): ?string
    {
        return $categoryId === null ? null : ($this->categoriesById[$categoryId]->code ?? null);
    }

    private function email(RowContext $row): ?string
    {
        if (! $row->filled('email')) {
            return null;
        }

        $email = (string) $row->cell('email')->text;
        if (mb_strlen($email) > 255 || ! (new EmailValidator)->isValid($email, new RFCValidation)) {
            $row->fail('email', __('imports.errors.email', ['value' => $row->cell('email')->display()]));

            return null;
        }

        return $email;
    }

    private function category(RowContext $row): ?ClientCategory
    {
        if (! $row->filled('categorie')) {
            return null;
        }

        $category = $this->categories[self::fold((string) $row->cell('categorie')->text)] ?? null;
        if ($category === null) {
            $row->fail('categorie', __('imports.errors.unknown_category', ['value' => $row->cell('categorie')->display()]));
        }

        return $category;
    }

    private function region(RowContext $row): ?Region
    {
        if (! $row->filled('code_region')) {
            return null;
        }

        $region = $this->regionsByCode[mb_strtoupper((string) $row->cell('code_region')->text)] ?? null;
        if ($region === null) {
            $row->fail('code_region', __('imports.errors.unknown_region', ['value' => $row->cell('code_region')->display()]));
        }

        return $region;
    }

    /** Nouvelle région du client : active et dans le périmètre de l'auteur. */
    private function regionAvailable(RowContext $row, Region $region): bool
    {
        if (! $region->is_active) {
            $row->fail('code_region', __('imports.errors.inactive_region', ['value' => $region->code]));

            return false;
        }

        if ($this->regionIds !== null && ! in_array($region->id, $this->regionIds, true)) {
            $row->fail('code_region', __('imports.errors.region_out_of_perimeter', ['value' => $region->code]));

            return false;
        }

        return true;
    }

    /** Coordonnée GPS en degrés décimaux, rendue comme le cast decimal:7. */
    private function coordinate(RowContext $row, string $column, int $bound): ?string
    {
        if (! $row->filled($column)) {
            return null;
        }

        $value = $this->decimal($row, $column);
        if ($value === null || $value < -$bound || $value > $bound) {
            $row->fail($column, __("imports.errors.{$column}", ['value' => $row->cell($column)->display()]));

            return null;
        }

        return number_format($value, 7, '.', '');
    }

    private function status(RowContext $row): ?ClientStatus
    {
        if (! $row->filled('statut')) {
            return null;
        }

        $status = ClientStatus::fromSpreadsheet((string) $row->cell('statut')->text);
        if ($status === null) {
            $row->fail('statut', __('imports.errors.client_status', ['value' => $row->cell('statut')->display()]));
        }

        return $status;
    }
}
