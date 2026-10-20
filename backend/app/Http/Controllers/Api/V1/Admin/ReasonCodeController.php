<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reasons\ReasonCodeBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reasons\ListAdminReasonCodesRequest;
use App\Http\Requests\Reasons\StoreReasonCodeRequest;
use App\Http\Requests\Reasons\UpdateReasonCodeRequest;
use App\Http\Resources\Reasons\ReasonCodeResource;
use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Motifs paramétrables par l'Admin (EF-ADM-05, I-82 ; contrat du lot 6,
 * §4) : liste (désactivés compris), création, modification, avec
 * ReasonCodeBook. Le droit `settings.manage` est contrôlé par la route puis
 * par les requêtes (403 avant 404 avant 422). Le code et le type ne
 * changent pas après la création, il n'y a pas de suppression : on
 * désactive. Chaque écriture est tracée dans le journal `reasons`.
 */
class ReasonCodeController extends Controller
{
    public function __construct(private readonly ReasonCodeBook $book) {}

    /** GET /admin/reason-codes?filter[type]= : par type, puis ordre, puis libellé. */
    public function index(ListAdminReasonCodesRequest $request): JsonResponse
    {
        $reasons = ReasonCode::query()
            ->whereIn('type', array_map(fn ($type) => $type->value, $request->types()))
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return response()->json(['data' => ReasonCodeResource::collection($reasons)]);
    }

    /** POST /admin/reason-codes : 201. */
    public function store(StoreReasonCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reason = $this->book->create($request->safe()->only(['type', 'code', 'label', 'requires_comment', 'sort_order']), $user);

        return (new ReasonCodeResource($reason->refresh()))->response()->setStatusCode(201);
    }

    /** PATCH /admin/reason-codes/{id} : libellé, activation, commentaire exigé, ordre. */
    public function update(UpdateReasonCodeRequest $request, string $reasonCode): ReasonCodeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ReasonCodeResource($this->book->update($request->reason(), $request->changes(), $user)->refresh());
    }
}
