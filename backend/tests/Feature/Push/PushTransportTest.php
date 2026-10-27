<?php

use App\Domain\Push\LogPushTransport;
use App\Domain\Push\PushTransport;
use App\Domain\Push\WebPushTransport;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\Encryption;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Feature\Push\PushTesting;

// Transports des push (contrat du lot 8, §2.4) : Web Push (VAPID, chiffrement,
// en-têtes) sans réseau, au travers d'un client HTTP simulé ; journal de recette.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    PushTesting::configureVapid();
    $this->user = User::factory()->create();
    // Client HTTP simulé : réponse par endpoint, requêtes conservées.
    $this->http = new class implements ClientInterface
    {
        /** @var list<RequestInterface> */
        public array $requests = [];

        /** @var array<string, int> */
        public array $statuses = [];

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;
            $status = $this->statuses[(string) $request->getUri()] ?? 201;
            if ($status === 0) {
                throw new ConnectException('Connexion refusée', $request);
            }

            return new Response($status);
        }
    };
    $this->transport = new WebPushTransport($this->http);
    $this->headers = ['ttl' => 21600, 'urgency' => 'high', 'topic' => 'VALIDATION_REMINDER'];
});

it('encrypts the payload and sends it with the VAPID and contract headers', function () {
    $device = PushSubscription::factory()->create(['user_id' => $this->user->id]);

    $outcomes = $this->transport->send([$device], '{"title":"Rappel : 3 commandes à valider"}', $this->headers);

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0]->endpoint)->toBe($device->endpoint)
        ->and($outcomes[0]->ok)->toBeTrue()
        ->and($outcomes[0]->status)->toBe(201)
        ->and($outcomes[0]->expired)->toBeFalse();

    $request = $this->http->requests[0];
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe($device->endpoint)
        ->and($request->getHeaderLine('TTL'))->toBe('21600')
        ->and($request->getHeaderLine('Urgency'))->toBe('high')
        ->and($request->getHeaderLine('Topic'))->toBe('VALIDATION_REMINDER')
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid t=')
        ->and($request->getHeaderLine('Authorization'))->toContain('k='.PushTesting::publicKey());
    // Contenu chiffré : le texte n'apparaît pas, et le remplissage masque sa longueur.
    $body = (string) $request->getBody();
    expect($body)->not->toContain('Rappel')
        ->and(strlen($body))->toBeGreaterThan(Encryption::MAX_COMPATIBILITY_PAYLOAD_LENGTH);

    $this->transport->send([$device], '{"title":"Rappel"}', $this->headers);
    expect(strlen((string) $this->http->requests[1]->getBody()))->toBe(strlen($body));
});

it('reports expired devices, refused messages and network errors per device', function () {
    $devices = PushSubscription::factory()->count(5)->create(['user_id' => $this->user->id]);
    $this->http->statuses = [
        $devices[1]->endpoint => 410,
        $devices[2]->endpoint => 404,
        $devices[3]->endpoint => 500,
        $devices[4]->endpoint => 0,
    ];

    $outcomes = $this->transport->send($devices, '{"title":"Titre"}', ['ttl' => 60, 'urgency' => 'normal', 'topic' => null]);

    expect(array_map(fn ($outcome) => [$outcome->endpoint, $outcome->ok, $outcome->status, $outcome->expired], $outcomes))->toBe([
        [$devices[0]->endpoint, true, 201, false],
        [$devices[1]->endpoint, false, 410, true],
        [$devices[2]->endpoint, false, 404, true],
        [$devices[3]->endpoint, false, 500, false],
        [$devices[4]->endpoint, false, null, false],
    ])
        ->and($this->http->requests[0]->hasHeader('Topic'))->toBeFalse()
        ->and($this->http->requests[0]->getHeaderLine('Urgency'))->toBe('normal');
});

it('never writes outside the allowed push services and isolates an unusable device key', function () {
    $internal = PushSubscription::factory()->create(['user_id' => $this->user->id, 'endpoint' => 'https://10.0.0.5/push']);
    // 65 octets, mais pas un point de la courbe P-256 : chiffrement impossible.
    $broken = PushSubscription::factory()->create(['user_id' => $this->user->id, 'public_key' => rtrim(strtr(base64_encode("\x04".str_repeat("\x00", 64)), '+/', '-_'), '=')]);
    $device = PushSubscription::factory()->create(['user_id' => $this->user->id]);

    $outcomes = $this->transport->send([$internal, $broken, $device], '{"title":"Titre"}', $this->headers);

    expect(array_map(fn ($outcome) => [$outcome->ok, $outcome->expired], $outcomes))->toBe([[false, false], [false, false], [true, false]])
        ->and($this->http->requests)->toHaveCount(1)
        ->and((string) $this->http->requests[0]->getUri())->toBe($device->endpoint);
});

it('uses the Web Push transport by default and the log transport for acceptance', function () {
    expect(app(PushTransport::class))->toBeInstanceOf(WebPushTransport::class);

    config(['ramymaak.push.transport' => 'log']);
    expect(app(PushTransport::class))->toBeInstanceOf(LogPushTransport::class);
});

it('writes one JSON line per message in the push log, without sending anything', function () {
    $path = tempnam(sys_get_temp_dir(), 'push-log-');
    $devices = PushSubscription::factory()->count(2)->create(['user_id' => $this->user->id]);

    $outcomes = (new LogPushTransport($path))->send($devices, '{"title":"Commande RMK-261008-00042 validée","count":1}', $this->headers);

    $lines = array_map(fn (string $line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES));
    unlink($path);
    expect($lines)->toBe([
        ['at' => now()->toIso8601String(), 'user_id' => $this->user->id, 'endpoint' => $devices[0]->endpoint, 'payload' => ['title' => 'Commande RMK-261008-00042 validée', 'count' => 1], 'options' => $this->headers],
        ['at' => now()->toIso8601String(), 'user_id' => $this->user->id, 'endpoint' => $devices[1]->endpoint, 'payload' => ['title' => 'Commande RMK-261008-00042 validée', 'count' => 1], 'options' => $this->headers],
    ])
        ->and(array_map(fn ($outcome) => $outcome->ok, $outcomes))->toBe([true, true]);
});
