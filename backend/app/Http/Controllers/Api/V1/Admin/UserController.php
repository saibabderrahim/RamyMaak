<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Access\Role;
use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\SessionRevoker;
use App\Domain\Auth\TemporaryPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Gestion des comptes (EF-ADM-01).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AuthAudit $audit,
        private readonly SessionRevoker $revoker,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = QueryBuilder::for(User::query()->with('roles'))
            ->allowedFilters(
                AllowedFilter::callback('role', function (Builder $query, mixed $value): void {
                    $roles = collect((array) $value)->map(fn ($r) => Role::tryFrom((string) $r));
                    if ($roles->contains(null)) {
                        throw ValidationException::withMessages(['filter.role' => [__('users.unknown_role')]]);
                    }
                    $query->role($roles->map->value->all());
                }),
                AllowedFilter::exact('is_active'),
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $value).'%';
                    $query->where(fn (Builder $q) => $q->where('username', 'like', $term)->orWhere('name', 'like', $term));
                }),
            )
            ->allowedSorts('username', 'name', 'created_at', 'last_login_at')
            ->defaultSort('username')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $temporaryPassword = TemporaryPassword::generate();
        $role = Role::from($request->string('role')->value());

        $user = DB::transaction(function () use ($request, $role, $temporaryPassword): User {
            $user = User::create([
                ...$request->safe()->except('role'),
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ]);
            $user->assignRole($role->value);

            activity('users')
                ->performedOn($user)
                ->event('role.assigned')
                ->withProperties(['attributes' => ['role' => $role->value]])
                ->log('Rôle attribué');

            return $user;
        });

        // Le mot de passe temporaire n'est communiqué qu'une fois, pour la
        // fiche d'accès remise en main propre (EF-ADM-02).
        return response()->json([
            'user' => new UserResource($user->load('roles')),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        DB::transaction(function () use ($request, $user): void {
            $oldRole = $user->appRole();
            $newRole = $request->has('role') ? Role::from($request->string('role')->value()) : $oldRole;
            $wasActive = $user->is_active;

            $attributes = $request->safe()->except(['role', 'unlock']);
            if ($newRole !== Role::Client) {
                $attributes['client_id'] = null;
            }
            $user->fill($attributes);

            if ($request->boolean('unlock')) {
                $user->forceFill(['failed_logins' => 0, 'locked_until' => null]);
                activity('auth')->performedOn($user)->event('account.unlocked')->log('Compte déverrouillé');
            }

            $user->save();

            if ($newRole !== $oldRole) {
                $user->syncRoles([$newRole->value]);
                activity('users')
                    ->performedOn($user)
                    ->event('role.changed')
                    ->withProperties([
                        'old' => ['role' => $oldRole?->value],
                        'attributes' => ['role' => $newRole->value],
                    ])
                    ->log('Rôle modifié');
            }

            // Désactivation : révocation immédiate de toutes les sessions (EF-AUTH-07).
            if ($wasActive && ! $user->is_active) {
                $this->revoker->revokeAll($user);
            }
        });

        return new UserResource($user->refresh()->load('roles'));
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('resetPassword', $user);

        $temporaryPassword = TemporaryPassword::generate();

        DB::transaction(function () use ($request, $user, $temporaryPassword): void {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();

            $this->revoker->revokeAll($user);

            $this->audit->record(
                'password.reset',
                'Mot de passe réinitialisé',
                $request,
                subject: $user,
                causer: $request->user(),
            );
        });

        return response()->json([
            'user' => new UserResource($user->load('roles')),
            'temporary_password' => $temporaryPassword,
        ]);
    }
}
