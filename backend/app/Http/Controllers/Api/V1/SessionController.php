<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

/**
 * Appareils connectés (contrat du lot 8, §5). Provisoire : volet B.
 */
class SessionController extends Controller
{
    public function index(): never
    {
        abort(501);
    }

    public function destroy(): never
    {
        abort(501);
    }
}
