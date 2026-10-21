<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Documents\WorkFolder;
use App\Domain\Documents\XlsxLayout;
use App\Domain\Referentials\AssignmentKind;
use App\Models\Order;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once __DIR__.'/DocumentsWorld.php';

// Droits, périmètre et paramètres des documents de la journée (contrat du
// lot 6, §2 et §6) : bon de préparation (ADV, order.process), export (ADV ou
// Admin, dashboard.view), bon de commande (ADV, order.process). 403 avant
// 404 avant 422 ; périmètre RG-S05 à la date de la journée.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    documentsWorld($this);
    $this->none = User::factory()->create();

    $this->orderA = documentsOrder($this, $this->a, 'RMK-261008-00001', 'validated', [[$this->orange, 40, 40], [$this->pomme, 15, 10]]);
    documentsOrder($this, $this->o, 'RMK-261008-00002', 'validated', [[$this->orange, 25, 25]]);
    documentsOrder($this, $this->e, 'RMK-261008-00003', 'validated', [[$this->lben, 100, 100]]);

    $this->users = fn (string $role): ?User => match ($role) {
        'adv' => $this->adv,
        'admin' => $this->admin,
        'regional' => $this->regional,
        'client' => $this->clientUser,
        'none' => $this->none,
        'guest' => null,
    };
    $this->picking = fn (string $query = 'format=xlsx') => $this->getJson('/api/v1/adv/days/2026-10-08/picking-list?'.$query);
    $this->export = fn (string $query = 'format=csv') => $this->getJson('/api/v1/adv/days/2026-10-08/orders/export?'.$query);
    $this->pdf = fn (?string $id = null) => $this->getJson('/api/v1/adv/orders/'.($id ?? $this->orderA->public_id).'/pdf');
    // Corps d'un téléchargement, en flux ou non.
    $this->body = fn ($response): string => $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    $this->restricted = fn (Region $region, string $from = '2026-10-01', ?string $to = null): User => tap(User::factory()->role(Role::Adv)->create(), fn (User $user) => RegionAssignment::factory()->create([
        'region_id' => $region->id, 'user_id' => $user->id, 'kind' => AssignmentKind::Adv, 'starts_on' => $from, 'ends_on' => $to,
    ]));
});

describe('rights', function () {
    it('lets the ADV download the three documents', function () {
        $this->actingAs($this->adv);

        $picking = ($this->picking)('format=pdf');
        $picking->assertOk()->assertHeader('Content-Type', 'application/pdf');
        ($this->picking)('format=xlsx')->assertOk();
        foreach (['xlsx', 'csv', 'erp'] as $format) {
            ($this->export)('format='.$format)->assertOk();
        }
        $pdf = ($this->pdf)();
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($pdf->getContent(), 0, 5))->toBe('%PDF-')
            ->and($pdf->headers->get('Content-Disposition'))->toContain('bon-de-commande-RMK-261008-00001.pdf')
            ->and($pdf->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('lets the Admin export the day but neither print the picking list nor the order (I-87)', function () {
        $this->actingAs($this->admin);

        foreach (['xlsx', 'csv', 'erp'] as $format) {
            ($this->export)('format='.$format)->assertOk();
        }
        expectProblem(($this->picking)('format=pdf'), 403, 'FORBIDDEN');
        expectProblem(($this->picking)('format=xlsx'), 403, 'FORBIDDEN');
        expectProblem(($this->pdf)(), 403, 'FORBIDDEN');
    });

    it('refuses the regional, a client account and an account without role with 403', function (string $role, string $document) {
        $this->actingAs(($this->users)($role));

        expectProblem(match ($document) {
            'picking' => ($this->picking)('format=pdf'),
            'export' => ($this->export)('format=csv'),
            'pdf' => ($this->pdf)(),
        }, 403, 'FORBIDDEN');
    })->with(['regional', 'client', 'none'])->with(['picking', 'export', 'pdf']);

    it('requires an authenticated session', function (string $document) {
        expectProblem(match ($document) {
            'picking' => ($this->picking)('format=pdf'),
            'export' => ($this->export)('format=csv'),
            'pdf' => ($this->pdf)(),
        }, 401, 'UNAUTHENTICATED');
    })->with(['picking', 'export', 'pdf']);

    it('follows the permissions of the roles, not only the roles', function (Permission $revoked, string $refused, string $allowed) {
        SpatieRole::findByName(Role::Adv->value)->revokePermissionTo($revoked->value);
        $this->actingAs($this->adv);
        $call = fn (string $document) => $document === 'picking' ? ($this->picking)('format=xlsx') : ($this->export)('format=csv');

        expectProblem($call($refused), 403, 'FORBIDDEN');
        $call($allowed)->assertOk();
    })->with([
        'order.process' => [Permission::OrderProcess, 'picking', 'export'],
        'dashboard.view' => [Permission::DashboardView, 'export', 'picking'],
    ]);

    it('lets an ADV without order.process neither print the order', function () {
        SpatieRole::findByName(Role::Adv->value)->revokePermissionTo(Permission::OrderProcess->value);
        $this->actingAs($this->adv);

        expectProblem(($this->pdf)(), 403, 'FORBIDDEN');
    });
});

describe('order of the checks', function () {
    it('answers 403 before 404 and 422', function () {
        $this->actingAs($this->regional);

        // Région inconnue et format invalide : le droit d'abord.
        expectProblem(($this->picking)('format=zip&region=99999'), 403, 'FORBIDDEN');
        expectProblem(($this->export)('format=zip&filter[region]=99999'), 403, 'FORBIDDEN');
    });

    it('answers 404 to an unknown region before judging the shape (404 before 422)', function () {
        $this->actingAs($this->adv);

        expectProblem(($this->picking)('format=zip&region=99999'), 404, 'NOT_FOUND');
        expectProblem(($this->export)('format=zip&filter[region]=99999'), 404, 'NOT_FOUND');
    });

    it('answers 404 to an unknown or malformed day', function (string $path) {
        $this->actingAs($this->adv);

        expectProblem($this->getJson('/api/v1/adv/days/'.$path), 404, 'NOT_FOUND');
    })->with([
        'jour inconnu, bon' => '2026-10-20/picking-list',
        'jour inconnu, export' => '2026-10-20/orders/export',
        'jour mal formé' => 'demain/picking-list',
        'jour inexistant' => '2026-02-30/orders/export',
    ]);

    it('answers 404 to a malformed order identifier before any query', function () {
        $this->actingAs($this->adv);

        expectProblem(($this->pdf)('pas-un-ulid'), 404, 'NOT_FOUND');
        expectProblem(($this->pdf)(strtoupper((string) Str::ulid())), 404, 'NOT_FOUND');
    });

    it('answers 404 to the order of a day that is not in the queue', function () {
        $this->actingAs($this->adv);
        $submitted = documentsOrder($this, $this->a, 'RMK-261008-00009', 'submitted', [[$this->orange, 5, null]]);

        expectProblem(($this->pdf)($submitted->public_id), 404, 'NOT_FOUND');
    });
});

describe('parameters', function () {
    it('answers 422 to an invalid parameter of the picking list', function (string $query, string $field) {
        $this->actingAs($this->adv);

        $response = ($this->picking)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'format inconnu' => ['format=docx', 'format'],
        'format csv' => ['format=csv', 'format'],
        'regroupement inconnu' => ['group=famille', 'group'],
        'statut inconnu' => ['status=VALIDATED,DRAFT', 'status'],
        'statut vide dans la liste' => ['status=VALIDATED,', 'status'],
        'commande annulée' => ['status=CANCELLED', 'status'],
        'commande rejetée' => ['status=REJECTED', 'status'],
        'commande envoyée' => ['status=SUBMITTED', 'status'],
        'statut imbriqué' => ['status[0][]=VALIDATED', 'status'],
        'région mal formée' => ['region=centre', 'region'],
        'région nulle' => ['region=0', 'region'],
    ]);

    it('answers 422 to an invalid parameter of the export', function (string $query, string $field) {
        $this->actingAs($this->adv);

        $response = ($this->export)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'format inconnu' => ['format=pdf', 'format'],
        'format vide de sens' => ['format[]=csv', 'format'],
        'statut inconnu' => ['format=csv&filter[status]=VALIDATED,DRAFT', 'filter.status'],
        'statut imbriqué' => ['format=csv&filter[status][0][]=VALIDATED', 'filter.status'],
        'région mal formée' => ['format=csv&filter[region]=centre', 'filter.region'],
        'filtre inconnu' => ['format=csv&filter[client]=1', 'filter'],
        'filtre qui n\'est pas une liste' => ['format=csv&filter=1', 'filter'],
    ]);

    it('picks the regions of the picking list by the region parameter', function () {
        $this->actingAs($this->adv);

        $rows = documentsSheetRows(($this->body)(($this->picking)('format=xlsx&region='.$this->est->id)));

        expect(array_values(array_unique(array_column(array_slice($rows, 8, -2), 0))))->toBe(['Est']);
    });

    it('applies the status, group and format parameters', function () {
        $this->actingAs($this->adv);

        $rows = documentsSheetRows(($this->body)(($this->picking)('format=xlsx&group=depot&status=VALIDATED')));

        expect($rows[3])->toBe(['Regroupement', 'Par dépôt'])->and($rows[4])->toBe(['Statuts inclus', 'Validée']);
    });
});

describe('perimeter (RG-S05)', function () {
    it('limits a restricted ADV to the regions assigned to them on the day', function () {
        $this->actingAs(($this->restricted)($this->centre));

        $export = documentsCsvRows(($this->body)(($this->export)('format=csv')));
        $picking = documentsSheetRows(($this->body)(($this->picking)('format=xlsx')));

        expect(array_values(array_unique(array_column(array_slice($export, 1), 2))))->toBe(['RMK-261008-00001'])
            ->and(array_values(array_unique(array_column(array_slice($picking, 8, -2), 0))))->toBe(['Centre'])
            ->and(end($picking)[0])->toBe('Total général')->and(end($picking)[5])->toBe(50);
    });

    it('answers 404 for a region outside the perimeter, in the export and the picking list', function () {
        $this->actingAs(($this->restricted)($this->centre));

        expectProblem(($this->export)('format=csv&filter[region]='.$this->est->id), 404, 'NOT_FOUND');
        expectProblem(($this->picking)('format=xlsx&region='.$this->est->id), 404, 'NOT_FOUND');
        ($this->export)('format=csv&filter[region]='.$this->centre->id)->assertOk();
        ($this->picking)('format=xlsx&region='.$this->centre->id)->assertOk();
    });

    it('judges the perimeter at the date of the day: an assignment that ended before sees nothing', function () {
        $this->actingAs(($this->restricted)($this->centre, '2026-09-01', '2026-10-05'));

        $export = documentsCsvRows(($this->body)(($this->export)('format=csv')));
        $picking = documentsSheetRows(($this->body)(($this->picking)('format=xlsx')));

        expect($export)->toHaveCount(1)->and(end($picking)[0])->toBe('Aucune commande');
        expectProblem(($this->export)('format=csv&filter[region]='.$this->centre->id), 404, 'NOT_FOUND');
    });

    it('judges the perimeter at the date of the day: an assignment that starts after sees nothing', function () {
        $this->actingAs(($this->restricted)($this->centre, '2026-10-09'));

        expect(documentsCsvRows(($this->body)(($this->export)('format=csv'))))->toHaveCount(1);
    });

    it('lets the Admin and an unrestricted ADV read every region', function (string $role) {
        $this->actingAs(($this->users)($role));

        $rows = documentsCsvRows(($this->body)(($this->export)('format=csv')));

        expect(array_values(array_unique(array_column(array_slice($rows, 1), 7))))->toEqualCanonicalizing(['Centre', 'Ouest', 'Est']);
    })->with(['admin', 'adv']);

    it('answers 404 to a region that does not exist, whoever asks', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem(($this->export)('format=csv&filter[region]=99999'), 404, 'NOT_FOUND');
    })->with(['admin', 'adv']);

    it('limits the order PDF to the orders of the perimeter of its day', function () {
        $this->actingAs(($this->restricted)($this->centre));

        ($this->pdf)()->assertOk();
        $est = Order::query()->where('number', 'RMK-261008-00003')->sole();
        expectProblem(($this->pdf)($est->public_id), 404, 'NOT_FOUND');
    });
});

describe('downloads', function () {
    it('are attachments that are never cached', function (string $call, string $type) {
        $this->actingAs($this->adv);
        $response = match ($call) {
            'picking-pdf' => ($this->picking)('format=pdf'),
            'picking-xlsx' => ($this->picking)('format=xlsx'),
            'export-xlsx' => ($this->export)('format=xlsx'),
            'export-csv' => ($this->export)('format=csv'),
            'export-erp' => ($this->export)('format=erp'),
            'order' => ($this->pdf)(),
        };

        expect($response->headers->get('Content-Type'))->toStartWith($type)
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    })->with([
        'picking-pdf' => ['picking-pdf', 'application/pdf'],
        'picking-xlsx' => ['picking-xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'export-xlsx' => ['export-xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'export-csv' => ['export-csv', 'text/csv'],
        'export-erp' => ['export-erp', 'text/csv'],
        'order' => ['order', 'application/pdf'],
    ]);

    it('leaves no file behind', function () {
        $this->actingAs($this->adv);
        // Dossier de travail privé : d'autres tests, en parallèle, écrivent aussi dans celui du système.
        $temp = sys_get_temp_dir().'/ramymaak-docs-'.bin2hex(random_bytes(6));
        mkdir($temp);
        config(['ramymaak.documents.temp_dir' => $temp]);
        $leftovers = fn (): array => glob($temp.'/*') ?: [];
        $before = $leftovers();

        foreach (['xlsx', 'csv', 'erp'] as $format) {
            ($this->body)(($this->export)('format='.$format));
        }
        ($this->body)(($this->picking)('format=xlsx'));
        ($this->picking)('format=pdf');
        ($this->pdf)();

        // Les fichiers de travail d'OpenSpout sont retirés à la fermeture de l'écrivain.
        expect(array_values(array_diff($leftovers(), $before)))->toBe([]);
        rmdir($temp);
    });

    it('leaves no file behind when the writing stops half-way', function () {
        $temp = sys_get_temp_dir().'/ramymaak-docs-'.bin2hex(random_bytes(6));
        mkdir($temp);
        config(['ramymaak.documents.temp_dir' => $temp]);
        $broken = (function () {
            yield from [];
            throw new RuntimeException('Connexion coupée');
        })();

        expect(fn () => (new XlsxLayout)->write($broken, $temp.'/../ramymaak-ignored.xlsx'))->toThrow(RuntimeException::class);
        // Dossier propre au téléchargement, retiré malgré l'erreur ; un arrêt brutal du script le retire à l'arrêt.
        expect(glob($temp.'/*') ?: [])->toBe([]);
        @unlink(sys_get_temp_dir().'/ramymaak-ignored.xlsx');
        rmdir($temp);
    });

    it('gives each download its own work folder, removed on demand', function () {
        $first = WorkFolder::create();
        $second = WorkFolder::create();

        expect($first)->not->toBe($second)->and(is_dir($first))->toBeTrue();
        WorkFolder::remove($first);
        WorkFolder::remove($second);
        expect(is_dir($first))->toBeFalse()->and(is_dir($second))->toBeFalse();
    });
});
