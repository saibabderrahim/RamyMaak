<?php

namespace App\Http\Requests\Documents;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Documents\PickingBy;
use App\Domain\Documents\PickingList;
use App\Domain\Orders\OrderStatus;
use Closure;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Bon de préparation (RG-A04, EF-ADV-05 ; contrat du lot 6, §6) :
 * `GET /adv/days/{day}/picking-list?format=pdf|xlsx&group=region|depot&status=…&region=…`.
 *
 * ADV seulement, avec `order.process` (l'Admin n'imprime pas, I-87). Par
 * défaut : PDF, regroupement par région, commandes Validées, En préparation
 * et Préparées (`status` : liste de statuts parmi Validée et suivantes).
 */
class PickingListRequest extends DayDocumentRequest
{
    public const FORMATS = ['pdf', 'xlsx'];

    protected function permission(): Permission
    {
        return Permission::OrderProcess;
    }

    protected function roles(): array
    {
        return [Role::Adv];
    }

    protected function requestedRegion(): mixed
    {
        return $this->query('region');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(self::FORMATS)],
            'group' => ['nullable', 'string', Rule::in(array_map(fn (PickingBy $by) => $by->value, PickingBy::cases()))],
            'status' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                $statuses = self::parseStatuses($value);
                if ($statuses === null) {
                    $fail(__('documents.request.status_unknown'));
                } elseif (array_diff(OrderStatus::values($statuses), OrderStatus::values(PickingList::allowedStatuses())) !== []) {
                    $fail(__('documents.request.picking_status_not_allowed'));
                }
            }],
            'region' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** `pdf` ou `xlsx` (PDF par défaut). */
    public function fileFormat(): string
    {
        return (string) ($this->validated('format') ?? 'pdf');
    }

    public function group(): PickingBy
    {
        return PickingBy::from((string) ($this->validated('group') ?? PickingBy::Region->value));
    }

    /**
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        $value = $this->validated('status');
        if ($value === null) {
            return PickingList::defaultStatuses();
        }

        return self::parseStatuses($value) ?? throw new LogicException('Statuts non validés.');
    }
}
