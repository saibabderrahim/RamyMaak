<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Settings\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Paramètres généraux (EF-ADM-04), exposés sous forme imbriquée :
 * { "cutoffs": { "ordering": "13:00", ... }, ... }.
 */
class SettingsController extends Controller
{
    public function show(Settings $settings): JsonResponse
    {
        return response()->json(Arr::undot($settings->all()));
    }

    public function update(Request $request, Settings $settings): JsonResponse
    {
        $settings->update($request->json()->all(), $request->user());

        return response()->json(Arr::undot($settings->all()));
    }
}
