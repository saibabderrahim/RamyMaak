<?php

namespace App\Http\Problems;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Convertit toute exception levée sous /api en réponse « Problem Details »
 * (RFC 9457) complétée du champ `code` (§11.1).
 */
final class ProblemRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::response(
                $e->errorCode,
                $e->getMessage(),
                $e->extra,
                $e->headers,
                $e->status(),
            ),
            $e instanceof ValidationException => self::response(
                ErrorCode::ValidationFailed,
                $e->validator->errors()->first(),
                ['errors' => $e->errors()],
            ),
            $e instanceof InvalidQuery => self::response(ErrorCode::ValidationFailed, $e->getMessage()),
            $e instanceof AuthenticationException => self::response(ErrorCode::Unauthenticated),
            $e instanceof TokenMismatchException => self::response(
                ErrorCode::Unauthenticated,
                __('errors.csrf_mismatch'),
                status: 419,
            ),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::response(ErrorCode::Forbidden),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::response(ErrorCode::NotFound),
            $e instanceof ThrottleRequestsException => self::response(
                ErrorCode::TooManyRequests,
                headers: $e->getHeaders(),
            ),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::response(
                ErrorCode::ServerError,
                config('app.debug') ? $e->getMessage() : null,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function response(
        ErrorCode $code,
        ?string $detail = null,
        array $extra = [],
        array $headers = [],
        ?int $status = null,
    ): JsonResponse {
        $status ??= $code->status();

        $body = [
            'type' => $code->type(),
            'title' => $code->title(),
            'status' => $status,
            'code' => $code->value,
            'detail' => $detail ?? $code->title(),
            ...$extra,
        ];

        return new JsonResponse($body, $status, [
            ...$headers,
            'Content-Type' => 'application/problem+json',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = match ($status) {
            401 => ErrorCode::Unauthenticated,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::NotFound,
            429 => ErrorCode::TooManyRequests,
            default => $status >= 500 ? ErrorCode::ServerError : ErrorCode::HttpError,
        };

        $detail = $e->getMessage() !== '' && $status < 500 ? $e->getMessage() : null;

        return self::response($code, $detail, headers: $e->getHeaders(), status: $status);
    }
}
