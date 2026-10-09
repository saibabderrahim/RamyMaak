<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Perimeter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientCategoryRequest;
use App\Http\Requests\Clients\UpdateClientCategoryRequest;
use App\Http\Resources\ClientCategoryResource;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Catégories de clients (EF-CLI-01) : grossiste, distributeur…
 */
class ClientCategoryController extends Controller
{
    public function __construct(private readonly Perimeter $perimeter) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewCategories', Client::class);

        $categories = ClientCategory::query()
            ->withCount($this->visibleClients($request->user()))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => ClientCategoryResource::collection($categories)]);
    }

    public function store(StoreClientCategoryRequest $request): JsonResponse
    {
        $category = ClientCategory::create($request->validated());

        return (new ClientCategoryResource($category->loadCount($this->visibleClients($request->user()))))
            ->response()
            ->setStatusCode(201);
    }

    /** Seul le libellé change : le code est la clé des fichiers Excel. */
    public function update(UpdateClientCategoryRequest $request, ClientCategory $category): ClientCategoryResource
    {
        $category->update($request->safe()->only('name'));

        return new ClientCategoryResource($category->loadCount($this->visibleClients($request->user())));
    }

    /**
     * Comptage des clients limité au périmètre de l'utilisateur (RG-S02).
     *
     * @return array<string, callable>
     */
    private function visibleClients(User $user): array
    {
        $visible = $this->perimeter->clients($user)->select('clients.id');

        return ['clients' => fn (Builder $query) => $query->whereIn('clients.id', $visible)];
    }
}
