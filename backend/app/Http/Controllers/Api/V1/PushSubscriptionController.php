<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Push\PushSubscriptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Push\DestroyPushSubscriptionRequest;
use App\Http\Requests\Push\StorePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Abonnements Web Push de l'appareil (contrat du lot 8, §2.2). Tous les
 * rôles ; 20 requêtes par minute et par utilisateur.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly PushSubscriptions $subscriptions) {}

    /** Enregistrement idempotent par endpoint : 201 à la création, 200 à la mise à jour. */
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $subscription = $this->subscriptions->register(
            $user,
            $request->subscription(),
            $request->hasSession() ? $request->session()->getId() : null,
            $request->userAgent(),
        );

        return response()->json([
            'endpoint' => $subscription->endpoint,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /** 204 dans tous les cas ; l'abonnement d'un autre compte reste intact. */
    public function destroy(DestroyPushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->subscriptions->unregister($user, (string) $request->validated('endpoint'));

        return response()->noContent();
    }
}
