<?php

namespace App\Domain\Reasons;

use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * Motifs paramétrables par l'Admin (EF-ADM-05, I-82) : création et
 * modification (libellé, activation, commentaire exigé, ordre). Le type et
 * le code sont immuables ; il n'y a pas de suppression, on désactive.
 * Chaque écriture est tracée dans le journal `reasons` (avant / après).
 */
final class ReasonCodeBook
{
    /** Champs modifiables après la création. */
    public const EDITABLE = ['label', 'is_active', 'requires_comment', 'sort_order'];

    public function __construct(private readonly ReasonCodes $reasons) {}

    /**
     * @param  array{type: ReasonType|string, code: string, label: string, requires_comment?: bool, sort_order?: int}  $data
     *
     * @throws ValidationException type inconnu, code mal formé, libellé vide ou trop long, code ou libellé déjà pris pour ce type
     */
    public function create(array $data, User $by): ReasonCode
    {
        $type = $data['type'] instanceof ReasonType ? $data['type'] : ReasonType::tryFrom((string) $data['type']);
        if ($type === null) {
            throw ValidationException::withMessages(['type' => [__('reasons.unknown_type')]]);
        }
        $code = trim($data['code']);
        if (preg_match(ReasonCode::CODE_PATTERN, $code) !== 1) {
            throw ValidationException::withMessages(['code' => [__('reasons.code_format')]]);
        }
        $this->assertCommentable($type, (bool) ($data['requires_comment'] ?? false));
        $attributes = [
            'type' => $type,
            'code' => $code,
            'label' => $this->label($data['label']),
            'requires_comment' => (bool) ($data['requires_comment'] ?? false),
            'is_active' => true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
        $this->assertUnique($type, $attributes);

        return $this->write($by, fn () => ReasonCode::create($attributes));
    }

    /**
     * @param  array<string, mixed>  $changes  label, is_active, requires_comment, sort_order
     *
     * @throws ValidationException type ou code transmis, libellé déjà pris pour ce type
     */
    public function update(ReasonCode $reason, array $changes, User $by): ReasonCode
    {
        $immutable = array_intersect(['type', 'code'], array_keys($changes));
        if ($immutable !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_values($immutable), [__('reasons.code_immutable')]));
        }
        $changes = array_intersect_key($changes, array_flip(self::EDITABLE));
        if (array_key_exists('requires_comment', $changes)) {
            $this->assertCommentable($reason->type, (bool) $changes['requires_comment']);
        }
        if (array_key_exists('label', $changes)) {
            $changes['label'] = $this->label($changes['label']);
            $this->assertUnique($reason->type, $changes, $reason->id);
        }

        return $this->write($by, function () use ($reason, $changes): ReasonCode {
            $reason->fill($changes)->save();

            return $reason;
        });
    }

    /**
     * Un écart d'expédition n'a pas de commentaire (aucune colonne ne le
     * garde sur la ligne) : son motif ne peut pas en exiger un (I-95).
     */
    private function assertCommentable(ReasonType $type, bool $requiresComment): void
    {
        if ($requiresComment && $type === ReasonType::ShippingGap) {
            throw ValidationException::withMessages(['requires_comment' => [__('reasons.comment_not_stored')]]);
        }
    }

    /** Libellé nettoyé, obligatoire, au plus ReasonCode::LABEL_MAX caractères. */
    private function label(mixed $label): string
    {
        $label = trim((string) $label);
        if ($label === '' || mb_strlen($label) > ReasonCode::LABEL_MAX) {
            throw ValidationException::withMessages(['label' => [__('reasons.label_format', ['max' => ReasonCode::LABEL_MAX])]]);
        }

        return $label;
    }

    /**
     * Code et libellé uniques par type (index uniques en filet).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertUnique(ReasonType $type, array $attributes, ?int $exceptId = null): void
    {
        $taken = fn (string $column): bool => isset($attributes[$column]) && ReasonCode::query()
            ->where('type', $type->value)
            ->where($column, $attributes[$column])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        $errors = [];
        if ($taken('code')) {
            $errors['code'] = [__('reasons.code_taken')];
        }
        if ($taken('label')) {
            $errors['label'] = [__('reasons.label_taken')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Écriture tracée au nom de l'auteur, puis motifs relus.
     *
     * @param  callable(): ReasonCode  $write
     */
    private function write(User $by, callable $write): ReasonCode
    {
        CauserResolver::setCauser($by);
        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            // Deux créations simultanées du même code ou libellé.
            throw ValidationException::withMessages(['code' => [__('reasons.code_taken')]]);
        } finally {
            CauserResolver::setCauser(null);
            $this->reasons->flush();
        }
    }
}
