<?php

namespace App\Http\Requests\Documents;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Documents\ExportFormat;
use App\Domain\Orders\OrderStatus;
use Closure;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Export des commandes de la journée (RG-A04, EF-ADV-07 ; contrat du lot 6,
 * §6) : `GET /adv/days/{day}/orders/export?format=xlsx|csv|erp&filter[status]=…&filter[region]=…`.
 *
 * ADV ou Admin avec `dashboard.view`. Par défaut : XLSX ; tous les statuts
 * pour XLSX et CSV, Validée à Expédiée pour le format ERP (qui refuse tout
 * autre statut).
 */
class OrderExportRequest extends DayDocumentRequest
{
    public const FILTERS = ['status', 'region'];

    protected function permission(): Permission
    {
        return Permission::DashboardView;
    }

    protected function roles(): array
    {
        return [Role::Adv, Role::Admin];
    }

    protected function requestedRegion(): mixed
    {
        $filter = $this->query('filter');

        return is_array($filter) ? ($filter['region'] ?? null) : null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(array_map(fn (ExportFormat $format) => $format->value, ExportFormat::cases()))],
            'filter' => ['nullable', 'array:'.implode(',', self::FILTERS)],
            'filter.status' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                $statuses = self::parseStatuses($value);
                $allowed = OrderStatus::values($this->rawFormat()->allowedStatuses());
                if ($statuses === null) {
                    $fail(__('documents.request.status_unknown'));
                } elseif (array_diff(OrderStatus::values($statuses), $allowed) !== []) {
                    $fail(__('documents.request.export_status_not_allowed'));
                }
            }],
            'filter.region' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function fileFormat(): ExportFormat
    {
        return ExportFormat::from((string) ($this->validated('format') ?? ExportFormat::Xlsx->value));
    }

    /**
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        $value = $this->validated('filter.status');
        if ($value === null) {
            return $this->fileFormat()->defaultStatuses();
        }

        return self::parseStatuses($value) ?? throw new LogicException('Statuts non validés.');
    }

    /** Format reçu avant validation : un format inconnu (refusé par sa règle) se lit comme XLSX. */
    private function rawFormat(): ExportFormat
    {
        return ExportFormat::tryFrom((string) $this->input('format', '')) ?? ExportFormat::Xlsx;
    }
}
