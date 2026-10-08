<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;

it('is reserved to the Admin role', function (Role $role) {
    $this->actingAs(userWithRole($role));

    expectProblem($this->getJson('/api/v1/admin/audit-logs'), 403, 'FORBIDDEN');
})->with([Role::Adv, Role::Regional, Role::Client]);

it('lists entries newest first with their author', function () {
    $admin = admin(['username' => 'boss']);
    $this->actingAs($admin);
    $user = userWithRole(Role::Client);
    $this->patchJson("/api/v1/admin/users/{$user->id}", ['name' => 'Renommé'])->assertOk();

    $response = $this->getJson('/api/v1/admin/audit-logs?filter[log_name]=users')->assertOk();

    $response->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.0.subject', ['type' => 'user', 'id' => $user->id])
        ->assertJsonPath('data.0.causer.username', 'boss')
        ->assertJsonPath('data.0.properties.attributes.name', 'Renommé');
});

it('filters by author, object, action and period (business days, Africa/Algiers)', function () {
    $admin = admin();
    $this->actingAs($admin);
    $target = userWithRole(Role::Client);
    AuditLog::query()->delete();

    // 07/10/2026 23:30 à Alger = 22:30 UTC : appartient au 07/10.
    Carbon::setTestNow(Carbon::parse('2026-10-07 22:30:00', 'UTC'));
    activity('users')->causedBy($admin)->performedOn($target)->event('updated')->log('a');
    // 08/10/2026 00:30 à Alger = 07/10 23:30 UTC : appartient au 08/10.
    Carbon::setTestNow(Carbon::parse('2026-10-07 23:30:00', 'UTC'));
    activity('auth')->causedBy($target)->performedOn($target)->event('login.failed')->log('b');
    Carbon::setTestNow();

    $events = fn (string $query) => $this->getJson('/api/v1/admin/audit-logs?'.$query)->assertOk()->json('data.*.description');

    expect($events('filter[from]=2026-10-08'))->toBe(['b'])
        ->and($events('filter[to]=2026-10-07'))->toBe(['a'])
        ->and($events('filter[causer_id]='.$admin->id))->toBe(['a'])
        ->and($events('filter[event]=login.failed'))->toBe(['b'])
        ->and($events('filter[subject_type]=user&filter[subject_id]='.$target->id))->toBe(['b', 'a']);

    expectProblem($this->getJson('/api/v1/admin/audit-logs?filter[from]=08/10/2026'), 422, 'VALIDATION_FAILED');
});

it('exports to CSV for Excel with formula injection neutralized', function () {
    $this->actingAs(admin());
    AuditLog::query()->delete();
    activity('test')->event('note')->log('=HYPERLINK("http://evil")');

    $response = $this->get('/api/v1/admin/audit-logs?format=csv')->assertOk();

    $csv = $response->streamedContent();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('journal-audit-')
        ->and($csv)->toStartWith("\xEF\xBB\xBFid;date;journal;action;description")
        ->and($csv)->toContain(';"\'=HYPERLINK(')
        ->and($csv)->not->toMatch('/;"?=HYPERLINK/');
});

it('cannot be altered through the application', function () {
    activity('test')->log('immuable');
    $entry = AuditLog::query()->latest('id')->first();

    expect(fn () => $entry->update(['description' => 'modifié']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
