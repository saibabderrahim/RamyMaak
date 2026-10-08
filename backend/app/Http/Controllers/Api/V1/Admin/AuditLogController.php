<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\BusinessClock;
use App\Support\Csv;
use App\Support\Pagination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation et export du journal d'audit (EF-ADM-06) : filtres par
 * utilisateur, objet, action et période. Le journal est en lecture seule.
 */
class AuditLogController extends Controller
{
    private const EXPORT_MAX_ROWS = 50_000;

    public function index(Request $request): AnonymousResourceCollection|StreamedResponse
    {
        $query = QueryBuilder::for(AuditLog::query()->with('causer'))
            ->allowedFilters(
                AllowedFilter::exact('causer_id'),
                AllowedFilter::exact('log_name'),
                AllowedFilter::exact('event'),
                AllowedFilter::exact('subject_type'),
                AllowedFilter::exact('subject_id'),
                AllowedFilter::callback('from', fn (Builder $q, mixed $day) => $q->where('created_at', '>=', $this->dayStart($day, 'filter.from'))),
                AllowedFilter::callback('to', fn (Builder $q, mixed $day) => $q->where('created_at', '<', $this->dayStart($day, 'filter.to')->addDay())),
            )
            ->allowedSorts('created_at', 'id')
            ->defaultSort('-id');

        if ($request->query('format') === 'csv') {
            return $this->export($query);
        }

        return AuditLogResource::collection(
            $query->paginate(Pagination::perPage($request))->withQueryString(),
        );
    }

    private function export(QueryBuilder $query): StreamedResponse
    {
        $rows = (function () use ($query) {
            $count = 0;
            foreach ($query->lazy(1000) as $log) {
                if (++$count > self::EXPORT_MAX_ROWS) {
                    break;
                }
                /** @var AuditLog $log */
                yield [
                    $log->id,
                    $log->created_at ? BusinessClock::toLocal($log->created_at)->format('d/m/Y H:i:s') : '',
                    $log->log_name,
                    $log->event,
                    $log->description,
                    $log->subject_type,
                    $log->subject_id,
                    $log->causer?->username,
                    $log->properties?->toArray(),
                ];
            }
        })();

        return Csv::download(
            'journal-audit-'.BusinessClock::now()->format('Ymd-His').'.csv',
            ['id', 'date', 'journal', 'action', 'description', 'objet', 'objet_id', 'auteur', 'details'],
            $rows,
        );
    }

    private function dayStart(mixed $day, string $field): CarbonImmutable
    {
        $parsed = is_string($day) ? BusinessClock::parseDay($day) : null;
        if ($parsed === null) {
            throw ValidationException::withMessages([$field => [__('validation.date_format', ['attribute' => $field, 'format' => 'AAAA-MM-JJ'])]]);
        }

        return $parsed->utc();
    }
}
