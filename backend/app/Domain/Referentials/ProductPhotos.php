<?php

namespace App\Domain\Referentials;

use App\Models\Product;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Drivers\Gd\GdDriver;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\MediaLibrary\MediaCollections\Filesystem as MediaFilesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Throwable;

/**
 * Photos d'un produit (EF-PRD-02, §9.8) : 1 principale + 4 secondaires, la
 * principale étant la première dans l'ordre de la collection.
 *
 * À l'envoi : contrôle du type réel du fichier (signature et en-tête de
 * l'image, jamais l'extension ni le type annoncé par le navigateur), puis
 * ré-encodage complet par GD — seuls les pixels sont conservés : métadonnées
 * EXIF/XMP, commentaires, profils et données ajoutées au fichier sont
 * abandonnés. L'original stocké est ce ré-encodage (WebP, 2 400 px au plus) ;
 * les variantes WebP 200 / 600 / 1 200 px sont générées par la médiathèque
 * (Product::registerMediaConversions). Chaque photo a sa propre adresse
 * (identifiant du média) : une photo remplacée change d'adresse, ce qui
 * autorise un cache d'un an.
 */
final class ProductPhotos
{
    /** Types réels acceptés (type MIME détecté → type PHP de l'image). */
    public const TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    public const MAX_MEGABYTES = 5;

    /**
     * Garde-fou contre les « bombes » de décompression : une image de 5 Mo
     * peut annoncer des dimensions qui épuiseraient la mémoire de PHP-FPM.
     */
    public const MAX_MEGAPIXELS = 25;

    /** Côté maximal de l'original ré-encodé (deux fois la grande variante). */
    public const MASTER_SIZE = 2400;

    /**
     * Qualités WebP de l'original, de la meilleure à la plus compacte : la
     * médiathèque refuse un fichier de plus de 5 Mo (media-library.max_file_size),
     * qu'une image très détaillée peut atteindre en qualité maximale.
     */
    public const MASTER_QUALITIES = [90, 75, 60];

    public function __construct(private readonly MediaFilesystem $files) {}

    /**
     * Photos du produit, la principale d'abord. Le produit est rattaché à
     * chaque média : le calcul des adresses ne le recharge pas.
     *
     * @return Collection<int, Media>
     */
    public function photos(Product $product): Collection
    {
        $photos = $product->relationLoaded('media')
            ? $product->getMedia(Product::PHOTOS)->collect()
            : $product->media()
                ->where('collection_name', Product::PHOTOS)
                ->orderBy('order_column')
                ->orderBy('id')
                ->get()
                ->collect();

        return $photos->each(fn (Media $photo) => $photo->setRelation('model', $product))->values();
    }

    /**
     * Motif du refus d'un fichier envoyé, ou null s'il est acceptable : taille
     * réelle, type réel (signature du fichier et en-tête de l'image
     * concordants) et dimensions.
     */
    public function rejectionReason(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            return __('products.photos.unreadable');
        }

        if ((int) filesize($path) > self::MAX_MEGABYTES * 1024 * 1024) {
            return __('products.photos.too_big', ['max' => self::MAX_MEGABYTES]);
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $header = @getimagesize($path);

        if (! is_string($detected) || ! array_key_exists($detected, self::TYPES)
            || $header === false || $header[2] !== self::TYPES[$detected]) {
            return __('products.photos.type');
        }

        [$width, $height] = $header;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_MEGAPIXELS * 1_000_000) {
            return __('products.photos.dimensions', ['max' => self::MAX_MEGAPIXELS]);
        }

        return null;
    }

    /**
     * Ajoute une photo, en dernière position, ou en principale si demandé.
     * 422 sur `photo` si le fichier est refusé ou si le produit a déjà
     * Product::MAX_PHOTOS photos.
     */
    public function add(Product $product, UploadedFile $file, bool $main = false): Media
    {
        $this->ensureRoomFor($product);

        $reason = $this->rejectionReason($file);
        if ($reason !== null) {
            throw ValidationException::withMessages(['photo' => [$reason]]);
        }

        $workspace = TemporaryDirectory::create();

        try {
            $master = $this->reencode((string) $file->getRealPath(), $workspace->path('photo.webp'));

            return DB::transaction(function () use ($product, $master, $main): Media {
                $this->lock($product);
                // Revérifié sous verrou : deux envois simultanés ne dépassent pas la limite.
                $this->ensureRoomFor($product);

                $uuid = (string) Str::uuid();

                try {
                    $photo = $this->store($product, $master, $uuid);

                    if ($main) {
                        $this->putFirst($product, $photo);
                    }

                    activity('referentials')
                        ->performedOn($product)
                        ->event('photo.added')
                        ->withProperties(['attributes' => [
                            'photo_id' => $photo->id,
                            'is_main' => $this->photos($product)->first()?->is($photo) ?? false,
                        ]])
                        ->log(__('products.audit.photo_added'));

                    return $photo;
                } catch (Throwable $e) {
                    // La transaction annule la ligne du média, pas les fichiers déjà écrits.
                    $this->discardFiles($uuid);

                    throw $e;
                }
            });
        } finally {
            $workspace->delete();
        }
    }

    /**
     * Fait de la photo la principale : elle passe en tête, les autres gardent
     * leur ordre.
     *
     * @return Collection<int, Media> photos réordonnées
     */
    public function makeMain(Product $product, Media $photo): Collection
    {
        return DB::transaction(function () use ($product, $photo): Collection {
            $this->lock($product);
            $current = $this->photos($product)->first();

            if ($current !== null && ! $current->is($photo)) {
                $this->putFirst($product, $photo);

                activity('referentials')
                    ->performedOn($product)
                    ->event('photo.main')
                    ->withProperties([
                        'old' => ['main_photo_id' => $current->id],
                        'attributes' => ['main_photo_id' => $photo->id],
                    ])
                    ->log(__('products.audit.photo_main'));
            }

            return $this->photos($product);
        });
    }

    /**
     * Supprime la photo et ses variantes ; la suivante devient principale si
     * besoin.
     */
    public function delete(Product $product, Media $photo): void
    {
        DB::transaction(function () use ($product, $photo): void {
            $this->lock($product);
            $wasMain = $this->photos($product)->first()?->is($photo) ?? false;

            activity('referentials')
                ->performedOn($product)
                ->event('photo.deleted')
                ->withProperties(['old' => ['photo_id' => $photo->id, 'is_main' => $wasMain]])
                ->log(__('products.audit.photo_deleted'));

            // L'observateur de la médiathèque supprime aussi les fichiers.
            $photo->delete();
        });
    }

    private function ensureRoomFor(Product $product): void
    {
        $count = $product->media()->where('collection_name', Product::PHOTOS)->count();

        if ($count >= Product::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photo' => [__('products.photos.limit', ['max' => Product::MAX_PHOTOS])],
            ]);
        }
    }

    /**
     * Ré-encode l'image (§9.8) : décodage par GD, redressement selon
     * l'orientation EXIF (perdue ensuite), réduction à MASTER_SIZE px au
     * plus, enregistrement en WebP. GD n'écrit aucune métadonnée.
     */
    private function reencode(string $source, string $target): string
    {
        try {
            $image = (new GdDriver)->loadFile($source, autoRotate: false);
        } catch (CouldNotLoadImage) {
            throw ValidationException::withMessages(['photo' => [__('products.photos.invalid')]]);
        }

        // Réduction avant rotation : la copie tournée reste petite en mémoire.
        $image->fit(Fit::Max, self::MASTER_SIZE, self::MASTER_SIZE);
        $image->autoRotate();
        $image->format('webp');

        $maxBytes = (int) config('media-library.max_file_size');

        foreach (self::MASTER_QUALITIES as $quality) {
            $image->quality($quality)->save($target);
            clearstatcache(true, $target);
            $size = is_file($target) ? (int) filesize($target) : 0;

            if ($size === 0) {
                throw ValidationException::withMessages(['photo' => [__('products.photos.invalid')]]);
            }
            if ($maxBytes <= 0 || $size <= $maxBytes) {
                return $target;
            }
        }

        throw ValidationException::withMessages(['photo' => [__('products.photos.too_detailed')]]);
    }

    /**
     * Enregistre l'original ré-encodé dans la collection ; les variantes sont
     * générées dans la foulée (conversions non différées). Nommage d'après le
     * code article : /media/{id}/conversions/p0042-thumb.webp.
     */
    private function store(Product $product, string $master, string $uuid): Media
    {
        return $product->addMedia($master)
            ->usingName($product->sku)
            ->usingFileName(Str::lower($product->sku).'.webp')
            ->withProperties(['uuid' => $uuid])
            ->toMediaCollection(Product::PHOTOS);
    }

    /** Supprime l'original et les variantes d'un média dont l'ajout échoue. */
    private function discardFiles(string $uuid): void
    {
        $orphan = Media::query()->where('uuid', $uuid)->first();

        if ($orphan !== null) {
            $this->files->removeAllFiles($orphan);
        }
    }

    private function putFirst(Product $product, Media $photo): void
    {
        $ids = $this->photos($product)
            ->reject(fn (Media $other) => $other->is($photo))
            ->pluck('id')
            ->prepend($photo->id)
            ->all();

        Media::setNewOrder($ids);
        $product->unsetRelation('media');
    }

    /** Sérialise les modifications des photos d'un même produit. */
    private function lock(Product $product): void
    {
        Product::query()->whereKey($product->getKey())->lockForUpdate()->first();
        $product->unsetRelation('media');
    }
}
