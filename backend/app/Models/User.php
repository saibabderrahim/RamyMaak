<?php

namespace App\Models;

use App\Domain\Access\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
            ->dontSubmitEmptyLogs();
    }
}
