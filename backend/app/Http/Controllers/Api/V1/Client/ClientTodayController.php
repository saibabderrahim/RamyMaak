<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Domain\Orders\OrderingWindow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ClientTodayRequest;
use App\Http\Resources\Orders\ClientTodayResource;

/**
 * Accueil et catalogue du jour du client (EF-CLT-01, EF-CLT-02 ; contrat du
 * lot 5, §3) : état de la journée de sa région, ses commandes, produits
 * commandables avec sa borne et son disponible (RG-C09). Rôle Client,
 * `order.view` et `limit.view`, compte rattaché à un client (403 sinon).
 */
class ClientTodayController extends Controller
{
    public function __construct(private readonly OrderingWindow $window) {}

    /** GET /client/today[?day=AAAA-MM-JJ] ; sans journée, celle que propose le serveur (I-42). */
    public function show(ClientTodayRequest $request): ClientTodayResource
    {
        $client = $request->client();

        return ClientTodayResource::for($client, $request->day() ?? $this->window->defaultDay($client));
    }
}
