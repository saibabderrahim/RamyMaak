<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Regions\StoreDepotRequest;
use App\Http\Requests\Regions\UpdateDepotRequest;
use App\Http\Resources\DepotResource;
use App\Models\Depot;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Sites d'expédition (option, EF-ADM-03), réservés aux gestionnaires des
 * régions. Le code ne change pas après la création.
 */
class DepotController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('manageDepots', Region::class);

        $depots = Depot::query()->withCount('regions')->orderBy('code')->get();

        return response()->json(['data' => DepotResource::collection($depots)]);
    }

    public function store(StoreDepotRequest $request): JsonResponse
    {
        $depot = Depot::create($request->validated());

        return (new DepotResource($depot->refresh()->loadCount('regions')))->response()->setStatusCode(201);
    }

    public function update(UpdateDepotRequest $request, Depot $depot): DepotResource
    {
        $depot->update($request->safe()->only(['name', 'address', 'is_active']));

        return new DepotResource($depot->refresh()->loadCount('regions'));
    }
}
