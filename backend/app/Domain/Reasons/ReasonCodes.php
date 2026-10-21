<?php

namespace App\Domain\Reasons;

use App\Models\ReasonCode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Motifs en lecture (EF-ADM-05, I-82) : motifs actifs d'un type pour les
 * listes de choix et les contrôles, libellé d'un code pour l'affichage
 * (motifs désactivés compris). Tous les motifs sont lus en une requête,
 * gardée le temps d'une requête HTTP ou d'une tâche (service « scoped »).
 *
 * Les écrivains de commandes lisent les motifs **avant** leur transaction
 * (preload()) : sous REPEATABLE READ, une lecture simple après BEGIN et
 * avant les verrous figerait l'instantané trop tôt (I-67).
 */
final class ReasonCodes
{
    /** @var array<string, array<string, ReasonCode>>|null type → code → motif */
    private ?array $codes = null;

    /** Charge tous les motifs (une requête) s'ils ne le sont pas déjà. */
    public function preload(): void
    {
        $this->codes ??= ReasonCode::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->groupBy(fn (ReasonCode $reason) => $reason->type->value)
            ->map(fn (Collection $reasons) => $reasons->keyBy('code')->all())
            ->all();
    }

    /**
     * Motifs actifs d'un type, par ordre puis libellé.
     *
     * @return list<ReasonCode>
     */
    public function active(ReasonType $type): array
    {
        return array_values(array_filter($this->ofType($type), fn (ReasonCode $reason) => $reason->is_active));
    }

    /** Motif d'un type, désactivé compris ; null s'il n'existe pas. */
    public function find(ReasonType $type, ?string $code): ?ReasonCode
    {
        return $code === null ? null : ($this->ofType($type)[$code] ?? null);
    }

    public function isActive(ReasonType $type, ?string $code): bool
    {
        return $this->find($type, $code)?->is_active ?? false;
    }

    /** Le motif exige un commentaire (ex. AUTRE). Un code inconnu n'en exige pas. */
    public function requiresComment(ReasonType $type, ?string $code): bool
    {
        return $this->find($type, $code)?->requires_comment ?? false;
    }

    /** Libellé d'un code (motif désactivé compris), le code lui-même s'il est inconnu, null sans code. */
    public function label(ReasonType $type, ?string $code): ?string
    {
        return $code === null ? null : ($this->find($type, $code)?->label ?? $code);
    }

    /**
     * Code et libellé, forme des réponses de l'API (`{code, label}`), null sans code.
     *
     * @return array{code: string, label: string}|null
     */
    public function ref(ReasonType $type, ?string $code): ?array
    {
        return $code === null ? null : ['code' => $code, 'label' => (string) $this->label($type, $code)];
    }

    /**
     * Motif et commentaire d'une décision sur la commande (rejet, annulation
     * ADV) : motif actif du type, commentaire nettoyé (vide → null), exigé
     * si le motif l'exige (ex. AUTRE), au plus `$max` caractères.
     *
     * @return array{code: string, comment: ?string}
     *
     * @throws ValidationException `reason_code`, `comment`
     */
    public function assertChoice(ReasonType $type, ?string $code, ?string $comment, int $max): array
    {
        $code = self::clean($code);
        $comment = self::clean($comment);
        $errors = [];
        if ($code === null || ! $this->isActive($type, $code)) {
            $errors['reason_code'] = [__('reasons.unknown')];
        } elseif ($comment === null && $this->requiresComment($type, $code)) {
            $errors['comment'] = [__('reasons.comment_required')];
        }
        if ($comment !== null && mb_strlen($comment) > $max) {
            $errors['comment'] = [__('reasons.comment_max', ['max' => $max])];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['code' => (string) $code, 'comment' => $comment];
    }

    /** Texte saisi nettoyé : espaces retirés, vide → null. */
    public static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /** Oublie les motifs lus (après une écriture de ReasonCodeBook). */
    public function flush(): void
    {
        $this->codes = null;
    }

    /** @return array<string, ReasonCode> code → motif */
    private function ofType(ReasonType $type): array
    {
        $this->preload();

        return $this->codes[$type->value] ?? [];
    }
}
