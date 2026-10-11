<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

// Centre de notifications : GET /notifications, POST /notifications/{id}/read,
// POST /notifications/read-all (EF-NOT-01 ; contrat du lot 3, §6).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    $this->regional = userWithRole(Role::Regional);
    $this->other = userWithRole(Role::Regional);

    // Notification enregistrée à l'heure donnée (fuseau métier), non lue par défaut.
    $this->notify = function (User $user, string $time, ?string $readAt = null): DatabaseNotification {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'QUOTAS_UPDATED',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => [
                'type' => 'QUOTAS_UPDATED', 'code' => 'N-02',
                'title' => "Notification de {$time}", 'body' => 'L\'ADV a modifié 2 quotas.',
                'url' => '/regional/quotas?day=2026-10-08',
            ],
            'read_at' => $readAt === null ? null : CarbonImmutable::parse("2026-10-08 {$readAt}", 'Africa/Algiers')->utc(),
            'created_at' => CarbonImmutable::parse("2026-10-08 {$time}", 'Africa/Algiers')->utc(),
        ]);
    };
});

it('lists the published quotas notification of a regional in the contract shape', function () {
    $adv = userWithRole(Role::Adv);
    $centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    RegionAssignment::factory()->create(['region_id' => $centre->id, 'user_id' => $this->regional->id]);
    $day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-08'), $adv);
    $book = app(QuotaBook::class);
    $book->saveDrafts($day, [['region_id' => $centre->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 500]], $adv);
    $book->publish($day, [$centre->id], $adv);

    $id = DatabaseNotification::query()->sole()->id;
    $this->actingAs($this->regional)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
        ->assertJsonPath('data', [[
            'id' => $id,
            'type' => 'QUOTAS_PUBLISHED',
            'code' => 'N-01',
            'title' => 'Quotas du 08/10 reçus — région Centre',
            'body' => 'À répartir avant 10:00.',
            'url' => '/regional/quotas?day=2026-10-08',
            'read_at' => null,
            'created_at' => '2026-10-08T06:30:00+00:00',
        ]]);

    $this->postJson("/api/v1/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('type', 'QUOTAS_PUBLISHED')
        ->assertJsonPath('read_at', '2026-10-08T06:30:00+00:00');
});

it('lists only the user\'s own notifications, newest first, paginated, with the unread count', function () {
    ($this->notify)($this->regional, '07:00', '07:10');
    ($this->notify)($this->regional, '08:00');
    ($this->notify)($this->regional, '09:00');
    ($this->notify)($this->other, '09:30');

    $this->actingAs($this->regional)
        ->getJson('/api/v1/notifications?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Notification de 09:00')
        ->assertJsonPath('data.1.title', 'Notification de 08:00')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        // Toutes les non lues de l'utilisateur, pas seulement celles de la page.
        ->assertJsonPath('meta.unread_count', 2);

    $this->getJson('/api/v1/notifications?per_page=2&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Notification de 07:00')
        ->assertJsonPath('data.0.read_at', '2026-10-08T06:10:00+00:00')
        ->assertJsonPath('meta.unread_count', 2);
});

it('filters unread (or read) notifications', function () {
    ($this->notify)($this->regional, '07:00', '07:10');
    ($this->notify)($this->regional, '08:00');
    ($this->notify)($this->regional, '09:00');
    $this->actingAs($this->regional);

    $this->getJson('/api/v1/notifications?filter[unread]=1')
        ->assertOk()
        ->assertJsonPath('data.*.title', ['Notification de 09:00', 'Notification de 08:00'])
        ->assertJsonPath('meta.unread_count', 2);
    $this->getJson('/api/v1/notifications?filter[unread]=true')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/notifications?filter[unread]=0')
        ->assertOk()
        ->assertJsonPath('data.*.title', ['Notification de 07:00']);
});

it('rejects an invalid filter value, an unknown filter and any sort with 422', function (string $query) {
    expectProblem($this->actingAs($this->regional)->getJson('/api/v1/notifications?'.$query), 422, 'VALIDATION_FAILED');
})->with([
    'unread=abc' => ['filter[unread]=abc'],
    'unread=1,0' => ['filter[unread]=1,0'],
    'unknown filter' => ['filter[type]=QUOTAS_UPDATED'],
    'sort' => ['sort=created_at'],
]);

it('is open to every signed-in user, clients included', function () {
    $client = userWithRole(Role::Client);
    $notification = ($this->notify)($client, '08:00');
    $this->actingAs($client);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.unread_count', 1);
    $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertExactJson(['updated' => 0]);
});

it('is closed to guests', function () {
    $notification = ($this->notify)($this->regional, '08:00');

    expectProblem($this->getJson('/api/v1/notifications'), 401, 'UNAUTHENTICATED');
    expectProblem($this->postJson("/api/v1/notifications/{$notification->id}/read"), 401, 'UNAUTHENTICATED');
    expectProblem($this->postJson('/api/v1/notifications/read-all'), 401, 'UNAUTHENTICATED');
});

it('marks one of the user\'s notifications as read, idempotently', function () {
    $notification = ($this->notify)($this->regional, '08:00');
    $this->actingAs($this->regional);

    $this->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('id', $notification->id)
        ->assertJsonPath('title', 'Notification de 08:00')
        ->assertJsonPath('read_at', '2026-10-08T06:30:00+00:00');

    // Relu plus tard : la date de lecture ne change pas.
    $this->travel(5)->minutes();
    $this->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('read_at', '2026-10-08T06:30:00+00:00');

    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0);
});

it('answers 404 for another user\'s notification or an unknown one', function () {
    $foreign = ($this->notify)($this->other, '08:00');
    $this->actingAs($this->regional);

    expectProblem($this->postJson("/api/v1/notifications/{$foreign->id}/read"), 404, 'NOT_FOUND');
    expectProblem($this->postJson('/api/v1/notifications/'.Str::uuid().'/read'), 404, 'NOT_FOUND');
    expectProblem($this->postJson('/api/v1/notifications/not-a-uuid/read'), 404, 'NOT_FOUND');

    expect($foreign->fresh()->read_at)->toBeNull();
});

it('marks all of the user\'s notifications as read and returns how many changed', function () {
    ($this->notify)($this->regional, '07:00', '07:10');
    ($this->notify)($this->regional, '08:00');
    ($this->notify)($this->regional, '09:00');
    $foreign = ($this->notify)($this->other, '09:30');
    $this->actingAs($this->regional);

    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertExactJson(['updated' => 2]);
    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertExactJson(['updated' => 0]);

    $this->getJson('/api/v1/notifications')
        ->assertJsonPath('meta.unread_count', 0)
        ->assertJsonPath('data.0.read_at', '2026-10-08T06:30:00+00:00')
        // Lue avant : sa date de lecture est conservée.
        ->assertJsonPath('data.2.read_at', '2026-10-08T06:10:00+00:00');
    expect($foreign->fresh()->read_at)->toBeNull();
});
