<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Role;
use App\Domain\Auth\TemporaryPassword;
use App\Domain\Referentials\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientAccountsRequest;
use App\Models\Client;
use App\Models\User;
use App\Support\BusinessClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Facades\LogBatch;

/**
 * Création en masse des comptes clients et fiches d'accès PDF (EF-ADM-02) :
 * un compte par client sans compte et non inactif, identifiant = code client
 * en minuscules (EF-AUTH-01), mot de passe temporaire à usage unique
 * (EF-AUTH-02), communiqué une seule fois sur la fiche remise en main propre.
 */
class ClientAccountController extends Controller
{
    public function __construct(private readonly Perimeter $perimeter) {}

    public function store(StoreClientAccountsRequest $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $issuedOn = BusinessClock::today();

        // Tout ou rien : si le PDF échoue, aucun compte n'est créé, sans quoi
        // leurs mots de passe seraient perdus.
        [$created, $pdf] = DB::transaction(fn () => LogBatch::withinBatch(function () use ($request, $actor, $issuedOn): array {
            $sheets = $this->eligibleClients($actor, $request->clientIds())
                ->map(fn (Client $client) => $this->createAccount($client))
                ->values()
                ->all();

            if ($sheets === []) {
                throw ValidationException::withMessages(['client_ids' => [__('clients.accounts.none_created')]]);
            }

            // Polices réduites aux caractères utilisés : quelques dizaines de Ko
            // au lieu de plus d'un Mo ; 200 fiches se produisent en une quinzaine de secondes.
            $pdf = Pdf::loadView('pdf.client-access', [
                'sheets' => $sheets,
                'appUrl' => rtrim((string) config('app.url'), '/'),
                'issuedOn' => $issuedOn,
            ])
                ->setPaper('a4')
                ->setOption('isFontSubsettingEnabled', true)
                ->addInfo(['Title' => __('clients.access_sheet.document_title')])
                ->output();

            return [count($sheets), $pdf];
        }));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="fiches-acces-'.$issuedOn->format('Ymd').'.pdf"',
            'X-Accounts-Created' => (string) $created,
            // Mots de passe en clair : jamais en cache.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Clients du périmètre ni inactifs, ni déjà dotés d'un compte, dont
     * l'identifiant est libre ; verrouillés le temps de la transaction pour
     * qu'une double soumission ne crée pas deux comptes.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Client>
     */
    private function eligibleClients(User $actor, array $ids): Collection
    {
        $clients = $this->perimeter->clients($actor)
            ->whereKey($ids)
            ->where('status', '!=', ClientStatus::Inactive->value)
            ->whereDoesntHave('accounts')
            ->orderBy('code')
            ->lockForUpdate()
            ->get();

        $taken = User::query()
            ->whereIn('username', $clients->map(fn (Client $c) => User::normalizeUsername($c->code))->all())
            ->pluck('username')
            ->flip();

        return $clients->reject(fn (Client $c) => $taken->has(User::normalizeUsername($c->code)));
    }

    /**
     * @return array{code: string, name: string, trade_name: ?string, username: string, password: string}
     */
    private function createAccount(Client $client): array
    {
        $temporaryPassword = TemporaryPassword::generate();

        $account = User::create([
            'username' => $client->code,
            'name' => $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
            'client_id' => $client->id,
            'is_active' => true,
            'must_change_password' => true,
            'password' => $temporaryPassword,
        ]);
        $account->assignRole(Role::Client->value);

        activity('users')
            ->performedOn($account)
            ->event('client-account.created')
            ->withProperties(['attributes' => ['role' => Role::Client->value, 'client_id' => $client->id]])
            ->log(__('clients.accounts.audit'));

        return [
            'code' => $client->code,
            'name' => $client->name,
            'trade_name' => $client->trade_name,
            'username' => $account->username,
            'password' => $temporaryPassword,
        ];
    }
}
