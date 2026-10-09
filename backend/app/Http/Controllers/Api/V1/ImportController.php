<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Imports\ImportProcessor;
use App\Domain\Imports\ImportStatus;
use App\Domain\Imports\ImportTemplate;
use App\Domain\Imports\ImportType;
use App\Domain\Imports\WorkbookInspector;
use App\Http\Controllers\Controller;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Http\Resources\ImportResource;
use App\Jobs\AnalyzeImport;
use App\Models\Import;
use App\Models\User;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\Enums\SortDirection;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imports Excel des référentiels (§8.3, Annexes B.3 à B.5) : gabarits, dépôt
 * (.xlsx sans macros, 5 Mo, §8.4), analyse en tâche de fond, rapport et
 * fichier d'erreurs annoté, application tout ou rien après revalidation.
 */
class ImportController extends Controller
{
    /** Taille maximale d'un fichier déposé, en kilo-octets (§8.4). */
    private const MAX_KILOBYTES = 5 * 1024;

    public function __construct(
        private readonly ImportProcessor $processor,
        private readonly ImportTemplate $templates,
    ) {}

    /** Gabarit pré-rempli avec les données actuelles. */
    public function template(Request $request, string $type): StreamedResponse
    {
        $importType = ImportType::from(mb_strtoupper($type));
        Gate::authorize('create', [Import::class, $importType]);

        return $this->templates->download($importType, $request->user());
    }

    /** Historique : les imports de l'utilisateur, tous pour l'Admin. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Import::class);

        /** @var User $user */
        $user = $request->user();
        $query = Import::query()->with('user');
        if (! $user->can(Permission::UserManage->value)) {
            $query->where('user_id', $user->getKey());
        }

        // Ordre de dépôt : à date égale, l'identifiant départage.
        $byCreation = AllowedSort::callback('created_at', function (Builder $query, bool $descending): void {
            $direction = $descending ? 'desc' : 'asc';
            $query->orderBy('created_at', $direction)->orderBy('id', $direction);
        })->defaultDirection(SortDirection::Descending);

        $imports = QueryBuilder::for($query)
            ->allowedFilters(
                AllowedFilter::callback('type', function (Builder $query, mixed $value): void {
                    $query->whereIn('type', $this->enumValues($value, ImportType::class, 'type', 'imports.unknown_type'));
                }),
                AllowedFilter::callback('status', function (Builder $query, mixed $value): void {
                    $query->whereIn('status', $this->enumValues($value, ImportStatus::class, 'status', 'imports.unknown_status'));
                }),
            )
            ->allowedSorts($byCreation)
            ->defaultSort($byCreation)
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return ImportResource::collection($imports);
    }

    /**
     * Dépôt d'un fichier : droits du type contrôlés avant la validation,
     * contenu vérifié (classeur .xlsx sans macros, §8.4), stockage hors de la
     * partie publique, analyse en file d'attente.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('import', Import::class);

        $type = is_string($request->input('type')) ? ImportType::tryFrom($request->input('type')) : null;
        if ($type !== null) {
            Gate::authorize('create', [Import::class, $type]);
        }

        $request->validate([
            'type' => ['required', Rule::enum(ImportType::class)],
            'file' => ['required', 'file', 'extensions:xlsx', 'max:'.self::MAX_KILOBYTES],
        ], [
            'file.file' => __('imports.file.format'),
            'file.uploaded' => __('imports.file.format'),
            'file.extensions' => __('imports.file.format'),
            'file.max' => __('imports.file.format'),
        ], __('imports.attributes'));

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $problem = WorkbookInspector::problem((string) $file->getRealPath());
        if ($problem !== null) {
            throw ValidationException::withMessages(['file' => [$problem]]);
        }

        $path = $file->storeAs('imports', Str::uuid()->toString().'.xlsx', 'local');
        if ($path === false) {
            throw new RuntimeException('Le fichier importé n\'a pas pu être enregistré.');
        }

        /** @var ImportType $type */
        $import = Import::create([
            'type' => $type,
            'status' => ImportStatus::Pending,
            'original_name' => self::originalName($file),
            'file_path' => $path,
            'user_id' => $request->user()->getKey(),
        ]);

        AnalyzeImport::dispatch($import);

        return (new ImportResource($import->refresh()->load('user')))
            ->response()
            ->setStatusCode(202);
    }

    /** Suivi de la progression et rapport, par interrogation. */
    public function show(Import $import): ImportResource
    {
        Gate::authorize('view', $import);

        return new ImportResource($import->load('user'));
    }

    /** Fichier d'origine annoté : colonne « erreurs » et cellules fautives surlignées. */
    public function errorReport(Import $import): StreamedResponse
    {
        Gate::authorize('view', $import);

        $disk = Storage::disk('local');
        $path = $import->error_report_path;
        if ($path === null || ! $disk->exists($path)) {
            throw new ApiException(ErrorCode::NotFound);
        }

        $name = pathinfo($import->original_name, PATHINFO_FILENAME);

        return $disk->download($path, __('imports.report.filename', ['name' => $name === '' ? 'import' : $name]), [
            'Content-Type' => ImportTemplate::CONTENT_TYPE,
        ]);
    }

    /**
     * Application (§8.1, étape 5) : READY seulement, tout ou rien dans une
     * transaction, données revalidées.
     */
    public function apply(Request $request, Import $import): ImportResource
    {
        Gate::authorize('apply', $import);

        $import = $this->processor->apply($import, $request->user());

        return new ImportResource($import->load('user'));
    }

    /** Nom du fichier tel que déposé, sans chemin ni caractères de contrôle. */
    private static function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return Str::limit($name === '' ? 'import.xlsx' : $name, 250, '');
    }

    /**
     * Valeurs d'un filtre sur une énumération ; une valeur inconnue → 422.
     *
     * @param  class-string<ImportType|ImportStatus>  $enum
     * @return list<string>
     */
    private function enumValues(mixed $value, string $enum, string $filter, string $message): array
    {
        $values = [];
        foreach ((array) $value as $item) {
            $case = is_string($item) ? $enum::tryFrom(mb_strtoupper($item)) : null;
            if ($case === null) {
                throw ValidationException::withMessages(["filter.{$filter}" => [__($message)]]);
            }
            $values[] = $case->value;
        }

        return $values;
    }
}
