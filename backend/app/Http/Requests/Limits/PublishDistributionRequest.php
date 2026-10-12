<?php

namespace App\Http\Requests\Limits;

use App\Domain\Access\Permission;

/**
 * « Valider et envoyer aux clients » (RG-B06, RG-B07) : la répartition de la
 * région désignée par `region_id`.
 */
class PublishDistributionRequest extends RegionalWriteRequest
{
    protected function permission(): Permission
    {
        return Permission::LimitPublish;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->regionRules();
    }
}
