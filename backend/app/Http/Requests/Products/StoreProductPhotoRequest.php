<?php

namespace App\Http\Requests\Products;

use App\Domain\Referentials\ProductPhotos;
use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Envoi d'une photo (EF-PRD-02, §9.8) : JPEG, PNG ou WebP de 5 Mo au plus,
 * type contrôlé sur le contenu du fichier (ProductPhotos::rejectionReason).
 * La limite de 5 photos est vérifiée par ProductPhotos, sous verrou.
 */
class StoreProductPhotoRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans product.manage, 403 et non 422.
    public function authorize(): bool
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $this->user()->can('update', $product);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'photo' => ['bail', 'required', 'file', 'max:'.(ProductPhotos::MAX_MEGABYTES * 1024),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $reason = $value instanceof UploadedFile ? app(ProductPhotos::class)->rejectionReason($value) : null;
                    if ($reason !== null) {
                        $fail($reason);
                    }
                },
            ],
            'main' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.photos.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['photo.max' => __('products.photos.too_big', ['max' => ProductPhotos::MAX_MEGABYTES])];
    }
}
