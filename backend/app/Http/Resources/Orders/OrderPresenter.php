<?php

namespace App\Http\Resources\Orders;

use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderStatus;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Domain\Referentials\ProductPhotos;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Support\Money;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Mise en forme commune d'une commande (contrat du lot 5, §3 et §4),
 * partagée par les vues du client et du régional : résumé, lignes (photo,
 * prix figé, montant), historique. Relations à charger avant :
 * OrderPresenter::RELATIONS.
 */
final class OrderPresenter
{
    /** Relations lues par lines() et history(). */
    public const RELATIONS = ['businessDay:id,day', 'lines.product:id,sku,name,short_name,sales_unit_label', 'lines.product.media', 'history.user:id,name'];

    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order): array
    {
        return [
            'id' => $order->public_id,
            'number' => $order->number,
            'day' => $order->businessDay->dayKey(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'version' => $order->version,
            'submitted_at' => $order->submitted_at->toIso8601String(),
            'total_ht' => Money::amount(Money::cents($order->total_ht)),
            'total_ttc' => Money::amount(Money::cents($order->total_ttc)),
            'lines_count' => $order->relationLoaded('lines') ? $order->lines->count() : (int) ($order->lines_count ?? $order->lines()->count()),
            'is_adjusted' => $order->is_adjusted,
        ];
    }

    /**
     * Lignes : prix HT et TVA figés, montant HT = prix × quantité (validée
     * dès la décision, sinon demandée), photo principale du produit, motifs
     * avec leur libellé (lot 6). `$shipping` faux : quantités expédiées et
     * écarts masqués (vue du client avant l'expédition, I-79).
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(Order $order, bool $shipping = true): array
    {
        $reasons = app(ReasonCodes::class);

        return $order->lines->map(function (OrderLine $line) use ($reasons, $shipping): array {
            $product = $line->product;
            $quantity = $line->validated_qty ?? $line->requested_qty;

            return [
                'product_id' => $line->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'short_name' => $product->short_name,
                'sales_unit_label' => $product->sales_unit_label,
                'photo' => self::photo($product),
                'requested_qty' => $line->requested_qty,
                'validated_qty' => $line->validated_qty,
                'shipped_qty' => $shipping ? $line->shipped_qty : null,
                'unit_price_ht' => Money::amount(Money::cents($line->unit_price_ht)),
                'vat_rate' => (float) $line->vat_rate,
                'amount_ht' => Money::amount(Money::cents($line->unit_price_ht) * $quantity),
                'adjustment_reason_code' => $line->adjustment_reason_code,
                'adjustment_reason' => $reasons->ref(ReasonType::Adjustment, $line->adjustment_reason_code),
                'adjustment_comment' => $line->adjustment_comment,
                'shipping_gap_reason' => $shipping ? $reasons->ref(ReasonType::ShippingGap, $line->shipping_gap_reason_code) : null,
            ];
        })->values()->all();
    }

    /**
     * Motif de la décision avec son libellé : rejet du régional ou
     * annulation exceptionnelle de l'ADV (I-75) ; null sinon.
     *
     * @return array{code: string, label: string}|null
     */
    public static function decisionReason(Order $order): ?array
    {
        $type = match ($order->status) {
            OrderStatus::Rejected => ReasonType::Rejection,
            OrderStatus::Cancelled => ReasonType::Cancellation,
            default => null,
        };

        return $type === null ? null : app(ReasonCodes::class)->ref($type, $order->decision_reason_code);
    }

    /**
     * Historique (§6.3). `$staffNames` : le nom de l'auteur est donné pour
     * tous (vue du régional) ; sinon seulement pour les comptes du client.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Order $order, bool $staffNames): array
    {
        return $order->history->map(function (OrderStatusHistory $entry) use ($staffNames): array {
            $actor = $entry->actor;

            return [
                'from' => $entry->from_status?->value,
                'to' => $entry->to_status->value,
                'version' => $entry->version,
                'at' => $entry->created_at->toIso8601String(),
                'actor' => $actor->value,
                'by_name' => $entry->user !== null && ($staffNames || $actor === OrderActor::Client) ? $entry->user->name : null,
                'comment' => $entry->comment,
            ];
        })->values()->all();
    }

    /** @return array{thumb: string, medium: string}|null */
    private static function photo(Product $product): ?array
    {
        /** @var Media|null $media */
        $media = app(ProductPhotos::class)->photos($product)->first();

        return $media === null ? null : ['thumb' => $media->getUrl('thumb'), 'medium' => $media->getUrl('medium')];
    }
}
