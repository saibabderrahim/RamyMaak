<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AuthAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePreferencesRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Préférences du compte (contrat du lot 8, §4) : tous les rôles
 * authentifiés ; réponse de la forme de GET /auth/me.
 */
class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request, AuthAudit $audit): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();
        $reminders = $request->boolean('reminders');

        DB::transaction(function () use ($request, $user, $reminders, $audit): void {
            $user->forceFill(['reminders_enabled' => $reminders])->save();
            $audit->record('preferences.updated', 'Préférences modifiées', $request, $user, properties: [
                'reminders' => $reminders,
            ]);
        });

        return new CurrentUserResource($user);
    }
}
