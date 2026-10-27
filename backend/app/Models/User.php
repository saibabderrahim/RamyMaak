<?php

namespace App\Models;

use App\Domain\Access\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $username
 * @property string $name
 * @property ?string $email
 * @property ?string $phone
 * @property ?int $client_id
 * @property bool $is_active
 * @property bool $must_change_password
 * @property int $failed_logins
 * @property ?Carbon $locked_until
 * @property ?Carbon $last_login_at
 * @property ?Carbon $password_changed_at
 * @property string $password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected string $guard_name = 'web';

    /**
     * Jeton « Rester connecté » de l'appareil de la requête en cours, en
     * clair, jamais enregistré : posé à sa création (connexion) ou à sa
     * lecture dans le cookie (I-113). Vide sinon : SessionGuard en crée alors
     * un nouveau à chaque connexion avec « Rester connecté ».
     */
    private ?string $deviceRememberToken = null;

    /** Ligne `remember_tokens` de cet appareil pour la requête en cours. */
    private ?int $rememberTokenId = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'client_id',
        'is_active',
        'must_change_password',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'reminders_enabled' => 'boolean',
            'failed_logins' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Identifiant insensible à la casse : « C0012 » et « c0012 » désignent le
     * même compte, quel que soit le moteur de base de données.
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeUsername($value));
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    /**
     * Client auquel le compte est rattaché (comptes clients).
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Canal privé des notifications de l'utilisateur (§9.6 : `user.{userId}`).
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user.'.$this->id;
    }

    /**
     * Abonnements Web Push des appareils du compte (lot 8).
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** @return HasMany<RegionAssignment, $this> */
    public function regionAssignments(): HasMany
    {
        return $this->hasMany(RegionAssignment::class);
    }

    /**
     * Jeton « Rester connecté » de l'appareil courant (I-113), et non plus la
     * colonne `remember_token`, partagée par tous les appareils du compte.
     */
    public function getRememberToken(): ?string
    {
        return $this->deviceRememberToken;
    }

    /** @param  string|null  $value */
    public function setRememberToken($value): void
    {
        $this->deviceRememberToken = $value === '' ? null : $value;
    }

    /** Appareil reconnu ou enregistré pendant la requête : jeton en clair et ligne. */
    public function rememberDevice(int $tokenId, string $token): void
    {
        $this->rememberTokenId = $tokenId;
        $this->deviceRememberToken = $token;
    }

    /** Ligne `remember_tokens` de l'appareil de la requête en cours, ou null. */
    public function rememberTokenId(): ?int
    {
        return $this->rememberTokenId;
    }

    /** L'appareil de la requête en cours n'est plus mémorisé. */
    public function forgetRememberedDevice(): void
    {
        $this->rememberTokenId = null;
        $this->deviceRememberToken = null;
    }

    public function appRole(): ?Role
    {
        $name = $this->getRoleNames()->first();

        return $name === null ? null : Role::tryFrom($name);
    }

    public function hasAppRole(Role $role): bool
    {
        return $this->appRole() === $role;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Les changements de mot de passe, de rôle et les verrouillages sont
        // journalisés explicitement par les services concernés.
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['username', 'name', 'email', 'phone', 'client_id', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Compte créé',
                'updated' => 'Compte modifié',
                'deleted' => 'Compte supprimé',
                default => $event,
            });
    }
}
