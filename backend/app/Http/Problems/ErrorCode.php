<?php

namespace App\Http\Problems;

/**
 * Codes d'erreur métier (§11.4), complétés des codes propres à
 * l'authentification ajoutés au lot 1.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case PasswordChangeRequired = 'PASSWORD_CHANGE_REQUIRED';
    case AccountLocked = 'ACCOUNT_LOCKED';
    case NotFound = 'NOT_FOUND';
    case ClientBlocked = 'CLIENT_BLOCKED';
    case DayNotOpen = 'DAY_NOT_OPEN';
    case CutoffPassed = 'CUTOFF_PASSED';
    case ProductNotAvailable = 'PRODUCT_NOT_AVAILABLE';
    case ClientLimitExceeded = 'CLIENT_LIMIT_EXCEEDED';
    case RegionQuotaExceeded = 'REGION_QUOTA_EXCEEDED';
    case QuotaBelowValidated = 'QUOTA_BELOW_VALIDATED';
    case MaxOrdersReached = 'MAX_ORDERS_REACHED';
    case OrderBelowMinimum = 'ORDER_BELOW_MINIMUM';
    case VersionConflict = 'VERSION_CONFLICT';
    case InvalidTransition = 'INVALID_TRANSITION';
    case ImportHasErrors = 'IMPORT_HAS_ERRORS';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case HttpError = 'HTTP_ERROR';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed, self::ProductNotAvailable, self::OrderBelowMinimum => 422,
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::Forbidden, self::AccountDisabled, self::PasswordChangeRequired, self::ClientBlocked => 403,
            self::AccountLocked => 423,
            self::NotFound => 404,
            self::DayNotOpen, self::CutoffPassed, self::ClientLimitExceeded, self::RegionQuotaExceeded,
            self::QuotaBelowValidated, self::MaxOrdersReached, self::VersionConflict,
            self::InvalidTransition, self::ImportHasErrors => 409,
            self::TooManyRequests => 429,
            self::HttpError => 400,
            self::ServerError => 500,
        };
    }

    public function title(): string
    {
        return __('errors.'.$this->value);
    }

    /**
     * Identifiant du type d'erreur, ex. « https://<domaine>/errors/region-quota-exceeded ».
     */
    public function type(): string
    {
        return rtrim((string) config('app.url'), '/').'/errors/'.str_replace('_', '-', strtolower($this->value));
    }
}
