<?php

namespace App\Http\Resources;

use App\Domain\Imports\ImportProcessor;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Import Excel et son rapport (§8.1, étape 3) : compteurs complets, 500
 * premières erreurs, 200 premières lignes créées ou modifiées. Le chemin des
 * fichiers stockés n'est jamais exposé.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Le rapport conserve une entrée de plus que l'affichage : elle signale la troncature.
        $errors = $this->errors ?? [];
        $preview = $this->preview ?? [];

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'rows_total' => $this->rows_total,
            'rows_processed' => $this->rows_processed,
            'rows_ok' => $this->rows_ok,
            'rows_error' => $this->rows_error,
            // Ordre des clés fixé ici : MySQL réordonne celles des colonnes JSON.
            'summary' => $this->summary === null ? null : [
                'create' => $this->summary['create'] ?? 0,
                'update' => $this->summary['update'] ?? 0,
                'unchanged' => $this->summary['unchanged'] ?? 0,
            ],
            'errors' => array_map(
                fn (array $error) => ['row' => $error['row'], 'column' => $error['column'], 'message' => $error['message']],
                array_slice($errors, 0, ImportProcessor::MAX_ERRORS),
            ),
            'errors_truncated' => count($errors) > ImportProcessor::MAX_ERRORS,
            'preview' => array_map(
                fn (array $row) => [
                    'row' => $row['row'],
                    'key' => $row['key'],
                    'action' => $row['action'],
                    // Objet JSON, même vide, dans l'ordre des colonnes du fichier.
                    'changes' => (object) collect($row['changes'])
                        ->mapWithKeys(fn (array $change) => [$change['field'] => ['old' => $change['old'], 'new' => $change['new']]])
                        ->all(),
                ],
                array_slice($preview, 0, ImportProcessor::MAX_PREVIEW),
            ),
            'preview_truncated' => count($preview) > ImportProcessor::MAX_PREVIEW,
            'has_error_report' => $this->error_report_path !== null,
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'name' => $this->user->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'failure_message' => $this->failure_message,
        ];
    }
}
