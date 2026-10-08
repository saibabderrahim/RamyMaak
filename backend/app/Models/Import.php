<?php

namespace App\Models;

use App\Domain\Imports\ImportStatus;
use App\Domain\Imports\ImportType;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Import d'un fichier Excel (§8) et son rapport d'analyse.
 *
 * @property int $id
 * @property ImportType $type
 * @property ?int $business_day_id
 * @property ?string $mode
 * @property ImportStatus $status
 * @property string $original_name
 * @property string $file_path
 * @property int $rows_total
 * @property int $rows_processed
 * @property int $rows_ok
 * @property int $rows_error
 * @property ?array<int, array{row: ?int, column: ?string, message: string}> $errors
 * @property ?array<string, int> $summary
 * @property ?array<int, array<string, mixed>> $preview
 * @property ?string $error_report_path
 * @property ?string $failure_message
 * @property int $user_id
 * @property ?Carbon $analyzed_at
 * @property ?Carbon $applied_at
 */
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $fillable = [
        'type', 'business_day_id', 'mode', 'status', 'original_name', 'file_path',
        'rows_total', 'rows_processed', 'rows_ok', 'rows_error', 'errors', 'summary',
        'preview', 'error_report_path', 'failure_message', 'user_id', 'analyzed_at', 'applied_at',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'business_day_id' => 'integer',
            'rows_total' => 'integer',
            'rows_processed' => 'integer',
            'rows_ok' => 'integer',
            'rows_error' => 'integer',
            'errors' => 'array',
            'summary' => 'array',
            'preview' => 'array',
            'user_id' => 'integer',
            'analyzed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
