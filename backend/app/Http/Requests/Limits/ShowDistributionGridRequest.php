<?php

namespace App\Http\Requests\Limits;

use App\Domain\Access\Permission;

/**
 * Grille de répartition lue par le régional (EF-REG-02) :
 * `?region={id}`, une région de son périmètre à la date de la journée.
 */
class ShowDistributionGridRequest extends DistributionRequest
{
    protected function permission(): Permission
    {
        return Permission::LimitView;
    }

    // Même lecture que la règle « integer » : la région contrôlée est celle validée.
    protected function regionId(): ?int
    {
        $value = $this->input('region');
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $id === false ? null : $id;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'region' => ['required', 'integer'],
        ];
    }
}
