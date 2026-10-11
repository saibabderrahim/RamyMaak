<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Support\FilterValue;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Centre de notifications (EF-NOT-01) : tout utilisateur connecté, client
 * compris, lit ses propres notifications et les marque comme lues. Celle
 * d'un autre utilisateur répond 404 (on ne révèle pas son existence).
 */
class NotificationController extends Controller
{
    /** Plus récentes d'abord ; `filter[unread]=1` ; `meta.unread_count`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = QueryBuilder::for($this->ownedBy($user))
            ->allowedFilters(
                AllowedFilter::callback('unread', function (Builder $query, mixed $value): void {
                    $values = FilterValue::scalars($value, 'unread');
                    $unread = count($values) === 1 ? filter_var($values[0], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
                    if ($unread === null) {
                        throw ValidationException::withMessages(['filter.unread' => [__('validation.invalid_filter')]]);
                    }

                    $unread ? $query->whereNull('read_at') : $query->whereNotNull('read_at');
                }),
            )
            // Ordre fixe (plus récentes d'abord) : tout tri demandé → 422.
            ->allowedSorts()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return NotificationResource::collection($notifications)->additional([
            'meta' => ['unread_count' => $this->ownedBy($user)->whereNull('read_at')->count()],
        ]);
    }

    /** Rejouable : une notification déjà lue garde sa date de lecture. */
    public function read(Request $request, string $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();

        $model = $this->ownedBy($user)->whereKey($notification)->firstOrFail();
        $model->markAsRead();

        return new NotificationResource($model);
    }

    /** « Tout marquer comme lu » : nombre de notifications passées à lues. */
    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->ownedBy($user)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    /** @return Builder<DatabaseNotification> */
    private function ownedBy(User $user): Builder
    {
        return DatabaseNotification::query()->whereMorphedTo('notifiable', $user);
    }
}
