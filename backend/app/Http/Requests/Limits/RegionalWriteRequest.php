<?php

namespace App\Http\Requests\Limits;

/**
 * Écriture du régional sur la grille : `region_id` (entier JSON) désigne la
 * région, contrôlée avant la validation du reste (404 hors périmètre).
 */
abstract class RegionalWriteRequest extends DistributionRequest
{
    protected function regionId(): ?int
    {
        $value = $this->input('region_id');

        return is_int($value) ? $value : null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function regionRules(): array
    {
        // Entier JSON strict : ni « 3 », ni 3.5, ni true.
        return ['region_id' => ['required', 'integer:strict']];
    }
}
