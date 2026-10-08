<?php

use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/test')->group(function () {
        Route::get('business', fn () => throw new ApiException(
            ErrorCode::RegionQuotaExceeded,
            'La validation dépasserait le quota de la région pour 1 produit.',
            ['violations' => [['product_id' => 57, 'max_allowed' => 10]]],
        ));
        Route::get('crash', fn () => throw new RuntimeException('secret internals'));
    });
});

it('renders business errors as RFC 9457 problem documents with a business code (§11.3)', function () {
    $response = $this->getJson('/api/test/business');

    expectProblem($response, 409, 'REGION_QUOTA_EXCEEDED');
    $response->assertExactJson([
        'type' => 'http://localhost/errors/region-quota-exceeded',
        'title' => 'Quota région dépassé',
        'status' => 409,
        'code' => 'REGION_QUOTA_EXCEEDED',
        'detail' => 'La validation dépasserait le quota de la région pour 1 produit.',
        'violations' => [['product_id' => 57, 'max_allowed' => 10]],
    ]);
});

it('hides internal errors when debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/test/crash');

    expectProblem($response, 500, 'SERVER_ERROR');
    expect($response->getContent())->not->toContain('secret internals');
});

it('answers unknown API routes with a problem document', function () {
    expectProblem($this->getJson('/api/v1/nowhere'), 404, 'NOT_FOUND');
});

it('maps every catalog code to the HTTP status of §11.4', function () {
    $expected = [
        'VALIDATION_FAILED' => 422, 'UNAUTHENTICATED' => 401, 'FORBIDDEN' => 403, 'ACCOUNT_LOCKED' => 423,
        'NOT_FOUND' => 404, 'CLIENT_BLOCKED' => 403, 'DAY_NOT_OPEN' => 409, 'CUTOFF_PASSED' => 409,
        'PRODUCT_NOT_AVAILABLE' => 422, 'CLIENT_LIMIT_EXCEEDED' => 409, 'REGION_QUOTA_EXCEEDED' => 409,
        'QUOTA_BELOW_VALIDATED' => 409, 'MAX_ORDERS_REACHED' => 409, 'VERSION_CONFLICT' => 409,
        'INVALID_TRANSITION' => 409, 'IMPORT_HAS_ERRORS' => 409, 'TOO_MANY_REQUESTS' => 429,
    ];

    foreach ($expected as $code => $status) {
        expect(ErrorCode::from($code)->status())->toBe($status, $code);
        expect(ErrorCode::from($code)->title())->not->toBe('errors.'.$code);
    }
});

it('serves the CSRF cookie for the SPA', function () {
    $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
});

it('exposes a health check for the containers', function () {
    $this->get('/up')->assertOk();
});
