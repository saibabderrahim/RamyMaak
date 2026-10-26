<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

/**
 * Préférences du compte (contrat du lot 8, §4). Provisoire : volet B.
 */
class PreferencesController extends Controller
{
    public function update(): never
    {
        abort(501);
    }
}
