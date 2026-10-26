<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

/**
 * Abonnements Web Push de l'appareil (contrat du lot 8, §2.2). Provisoire : volet A.
 */
class PushSubscriptionController extends Controller
{
    public function store(): never
    {
        abort(501);
    }

    public function destroy(): never
    {
        abort(501);
    }
}
