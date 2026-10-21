<?php

namespace App\Models;

use App\Domain\Reasons\ReasonType;
use App\Models\Concerns\LogsReferentialChanges;
use Database\Factories\ReasonCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Motif (§10.3, EF-ADM-05) : rejet, ajustement, écart d'expédition ou
 * annulation. `type` et `code` ne changent jamais (le code est recopié sur
 * les commandes) ; un motif se désactive, il n'est jamais supprimé, et un
 * motif désactivé garde son libellé (I-82). Écritures par ReasonCodeBook,
 * journal `reasons`.
 *
 * @property int $id
 * @property ReasonType $type
 * @property string $code
 * @property string $label
 * @property bool $requires_comment
 * @property bool $is_active
 * @property int $sort_order
 */
class ReasonCode extends Model
{
    /** @use HasFactory<ReasonCodeFactory> */
    use HasFactory, LogsReferentialChanges;

    /** Code : majuscules, chiffres et « _ », 2 à 30 caractères, une lettre d'abord. */
    public const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,29}$/';

    public const LABEL_MAX = 100;

    protected $fillable = ['type', 'code', 'label', 'requires_comment', 'is_active', 'sort_order'];

    protected $attributes = [
        'requires_comment' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Un motif ne se supprime pas : il se désactive.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ReasonType::class,
            'requires_comment' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => __('reasons.audit.created'), 'updated' => __('reasons.audit.updated'), 'deleted' => __('reasons.audit.deleted')];
    }

    protected static function auditLogName(): string
    {
        return 'reasons';
    }
}
