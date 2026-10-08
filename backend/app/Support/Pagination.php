<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Pagination des listes (§11.1) : `?page=2&per_page=50`, 100 au maximum.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $perPage));
    }
}
