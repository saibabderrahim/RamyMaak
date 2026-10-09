<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Referentials\PriceBook;
use App\Models\AuditLog;
use App\Models\Product;
use App\Support\BusinessClock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('media', config('filesystems.disks.media'));
    $this->admin = admin();
    $this->product = Product::factory()->create(['sku' => 'P0042']);
    $this->upload = fn (UploadedFile|string $photo, array $extra = [], ?Product $product = null) => $this->post(
        '/api/v1/products/'.($product ?? $this->product)->id.'/media',
        ['photo' => $photo, ...$extra],
    );
    // JPEG produit par GD, éventuellement précédé d'un segment EXIF (orientation)
    // et d'un commentaire, et suivi de données cachées après la fin de l'image.
    $this->jpeg = function (int $width, int $height, ?int $orientation = null, string $comment = '', string $trailer = ''): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 120, 20));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        $segments = '';
        if ($orientation !== null) {
            $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1)
                .pack('vvV', 0x0112, 3, 1).pack('vv', $orientation, 0).pack('V', 0);
            $exif = "Exif\0\0".$tiff;
            $segments .= "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        }
        if ($comment !== '') {
            $segments .= "\xFF\xFE".pack('n', strlen($comment) + 2).$comment;
        }

        return "\xFF\xD8".$segments.substr($bytes, 2).$trailer;
    };
    $this->stored = fn (int $mediaId, string $file) => Storage::disk('media')->path("{$mediaId}/{$file}");
});

it('stores a re-encoded photo with its three WebP variants', function () {
    $this->actingAs($this->admin);

    $response = ($this->upload)(UploadedFile::fake()->image('Photo produit.JPG', 1600, 1200));

    $id = $response->json('id');
    $response->assertCreated()
        ->assertExactJson([
            'id' => $id,
            'is_main' => true,
            'urls' => [
                'thumb' => "/media/{$id}/conversions/p0042-thumb.webp",
                'medium' => "/media/{$id}/conversions/p0042-medium.webp",
                'large' => "/media/{$id}/conversions/p0042-large.webp",
            ],
        ]);

    $media = Media::query()->findOrFail($id);
    expect($media->collection_name)->toBe(Product::PHOTOS)
        ->and($media->file_name)->toBe('p0042.webp')
        ->and($media->mime_type)->toBe('image/webp')
        ->and($media->disk)->toBe('media');

    // Original ré-encodé en WebP (non agrandi), variantes à 200 / 600 / 1 200 px.
    expect(getimagesize(($this->stored)($id, 'p0042.webp')))->toMatchArray([1600, 1200, 'mime' => 'image/webp'])
        ->and(getimagesize(($this->stored)($id, 'conversions/p0042-thumb.webp')))->toMatchArray([200, 150, 'mime' => 'image/webp'])
        ->and(getimagesize(($this->stored)($id, 'conversions/p0042-medium.webp')))->toMatchArray([600, 450, 'mime' => 'image/webp'])
        ->and(getimagesize(($this->stored)($id, 'conversions/p0042-large.webp')))->toMatchArray([1200, 900, 'mime' => 'image/webp']);
});

it('accepts JPEG, PNG and WebP photos', function (string $name) {
    $this->actingAs($this->admin);

    ($this->upload)(UploadedFile::fake()->image($name, 400, 300))->assertCreated();

    Storage::disk('media')->assertExists(Media::query()->sole()->id.'/conversions/p0042-thumb.webp');
})->with(['photo.jpg', 'photo.png', 'photo.webp']);

it('reduces a very large photo before storing it', function () {
    $this->actingAs($this->admin);

    $id = ($this->upload)(UploadedFile::fake()->image('panorama.jpg', 3000, 1000))->assertCreated()->json('id');

    expect(getimagesize(($this->stored)($id, 'p0042.webp')))->toMatchArray([2400, 800])
        ->and(getimagesize(($this->stored)($id, 'conversions/p0042-large.webp')))->toMatchArray([1200, 400]);
});

it('drops metadata and hidden content when re-encoding', function () {
    $this->actingAs($this->admin);
    $bytes = ($this->jpeg)(300, 200, comment: 'SECRET-COMMENT', trailer: '<?php echo "HIDDEN-PAYLOAD"; ?>');

    $id = ($this->upload)(UploadedFile::fake()->createWithContent('photo.jpg', $bytes))->assertCreated()->json('id');

    $files = Storage::disk('media')->allFiles((string) $id);
    expect($files)->toHaveCount(4);
    foreach ($files as $file) {
        expect(Storage::disk('media')->get($file))
            ->not->toContain('SECRET-COMMENT')
            ->not->toContain('HIDDEN-PAYLOAD')
            ->not->toContain('Exif');
    }
});

it('applies the EXIF orientation before dropping it', function () {
    $this->actingAs($this->admin);
    // Orientation 6 : photo prise téléphone tourné, à pivoter de 90°.
    $bytes = ($this->jpeg)(300, 100, orientation: 6);

    $id = ($this->upload)(UploadedFile::fake()->createWithContent('portrait.jpg', $bytes))->assertCreated()->json('id');

    expect(getimagesize(($this->stored)($id, 'p0042.webp')))->toMatchArray([100, 300])
        ->and(getimagesize(($this->stored)($id, 'conversions/p0042-thumb.webp')))->toMatchArray([67, 200]);
});

it('keeps the transparency of a PNG photo', function () {
    $this->actingAs($this->admin);
    $image = imagecreatetruecolor(100, 100);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 30, 30, 70, 70, imagecolorallocatealpha($image, 0, 128, 0, 0));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $id = ($this->upload)(UploadedFile::fake()->createWithContent('bouteille.png', $bytes))->assertCreated()->json('id');

    $stored = imagecreatefromwebp(($this->stored)($id, 'p0042.webp'));
    expect((imagecolorat($stored, 2, 2) >> 24) & 0x7F)->toBeGreaterThanOrEqual(120)
        ->and((imagecolorat($stored, 50, 50) >> 24) & 0x7F)->toBe(0);
});

it('checks the real file type, not the extension', function (UploadedFile|string $file) {
    $this->actingAs($this->admin);

    ($this->upload)($file)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors('photo');

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
})->with([
    'PHP script named .jpg' => fn () => UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["c"]); ?>'),
    'HTML named .png' => fn () => UploadedFile::fake()->createWithContent('photo.png', '<html><script>alert(1)</script></html>'),
    'JPEG signature without image' => fn () => UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0".str_repeat('A', 200)),
    'JPEG header without image data' => fn () => UploadedFile::fake()->createWithContent(
        'photo.jpg',
        substr($jpeg = ($this->jpeg)(300, 100), 0, (int) strpos($jpeg, "\xFF\xC4")),
    ),
    'SVG named .webp' => fn () => UploadedFile::fake()->createWithContent('photo.webp', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'),
    'GIF image' => fn () => UploadedFile::fake()->image('anime.gif', 50, 50),
    'PDF document' => fn () => UploadedFile::fake()->createWithContent('photo.jpg', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
    'not a file' => fn () => 'photo.jpg',
]);

it('rejects photos over 5 MB', function () {
    $this->actingAs($this->admin);

    ($this->upload)(UploadedFile::fake()->image('lourde.jpg', 100, 100)->size(5 * 1024 + 1))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo' => 'La photo ne doit pas dépasser 5 Mo.']);

    ($this->upload)(UploadedFile::fake()->image('limite.jpg', 100, 100)->size(5 * 1024))->assertCreated();
});

it('rejects images whose dimensions would exhaust the memory', function () {
    $this->actingAs($this->admin);
    // En-tête PNG annonçant 10 000 × 10 000 px (100 millions de pixels) pour quelques octets.
    $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 10000, 10000)."\x08\x02\x00\x00\x00";
    $bytes = $header.pack('N', crc32(substr($header, 12, 17))).pack('N', 0).'IEND'.pack('N', crc32('IEND'));

    ($this->upload)(UploadedFile::fake()->createWithContent('bombe.png', $bytes))
        ->assertJsonValidationErrors(['photo' => 'trop grande']);
});

it('re-encodes a very detailed photo more compactly to stay under the size limit', function () {
    $this->actingAs($this->admin);
    // Bruit aléatoire : le pire cas pour la compression WebP.
    mt_srand(42);
    $image = imagecreatetruecolor(240, 160);
    for ($y = 0; $y < 160; $y++) {
        for ($x = 0; $x < 240; $x++) {
            imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $best = ($this->upload)(UploadedFile::fake()->createWithContent('bruit.png', $bytes))->assertCreated()->json('id');
    $bestSize = filesize(($this->stored)($best, 'p0042.webp'));

    // Limite de la médiathèque juste sous la qualité maximale : une qualité inférieure est retenue.
    config(['media-library.max_file_size' => $bestSize - 1]);
    $compact = ($this->upload)(UploadedFile::fake()->createWithContent('bruit.png', $bytes))->assertCreated()->json('id');

    expect(filesize(($this->stored)($compact, 'p0042.webp')))->toBeLessThan($bestSize)
        ->and(getimagesize(($this->stored)($compact, 'p0042.webp')))->toMatchArray([240, 160, 'mime' => 'image/webp']);
    Storage::disk('media')->assertExists("{$compact}/conversions/p0042-thumb.webp");

    // Même la qualité la plus compacte dépasse la limite : refus, sans erreur serveur.
    config(['media-library.max_file_size' => 1]);
    ($this->upload)(UploadedFile::fake()->createWithContent('bruit.png', $bytes))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo' => 'trop détaillée']);

    expect($this->product->media()->count())->toBe(2);
});

it('leaves no file behind when adding a photo fails midway', function () {
    $this->actingAs($this->admin);
    // Incident après l'écriture de l'original sur le disque.
    Event::listen(MediaHasBeenAddedEvent::class, fn () => throw new RuntimeException('Disque indisponible'));

    expectProblem(($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40)), 500, 'SERVER_ERROR');

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('event', 'photo.added')->count())->toBe(0);
});

it('limits a product to five photos', function () {
    $this->actingAs($this->admin);

    foreach (range(1, Product::MAX_PHOTOS) as $n) {
        ($this->upload)(UploadedFile::fake()->image("photo{$n}.jpg", 40, 40))->assertCreated();
    }

    ($this->upload)(UploadedFile::fake()->image('photo6.jpg', 40, 40))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo' => 'déjà 5 photos']);

    expect($this->product->media()->count())->toBe(5);
    // La limite est propre à chaque produit.
    ($this->upload)(UploadedFile::fake()->image('autre.jpg', 40, 40), product: Product::factory()->create())->assertCreated();
});

it('makes the first photo the main one and reorders on request', function () {
    $this->actingAs($this->admin);
    $first = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->json('id');
    $second = ($this->upload)(UploadedFile::fake()->image('b.jpg', 40, 40))->assertJsonPath('is_main', false)->json('id');
    $third = ($this->upload)(UploadedFile::fake()->image('c.jpg', 40, 40))->json('id');

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertJsonPath('photos.*.id', [$first, $second, $third])
        ->assertJsonPath('photos.*.is_main', [true, false, false]);

    $this->patchJson("/api/v1/products/{$this->product->id}/media/{$third}", ['main' => true])
        ->assertOk()
        ->assertJsonPath('data.*.id', [$third, $first, $second])
        ->assertJsonPath('data.*.is_main', [true, false, false])
        ->assertJsonPath('data.0.urls.thumb', "/media/{$third}/conversions/p0042-thumb.webp");

    // Une nouvelle photo envoyée comme principale passe en tête.
    $fourth = ($this->upload)(UploadedFile::fake()->image('d.jpg', 40, 40), ['main' => '1'])
        ->assertCreated()
        ->assertJsonPath('is_main', true)
        ->json('id');

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertJsonPath('photos.*.id', [$fourth, $third, $first, $second]);
    $this->getJson('/api/v1/products')
        ->assertJsonPath('data.0.photos.*.id', [$fourth, $third, $first, $second]);

    // Désigner la principale actuelle ne change rien.
    $this->patchJson("/api/v1/products/{$this->product->id}/media/{$fourth}", ['main' => true])
        ->assertOk()
        ->assertJsonPath('data.*.id', [$fourth, $third, $first, $second]);
});

it('only accepts main: true when updating a photo', function () {
    $this->actingAs($this->admin);
    $id = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->json('id');

    $this->patchJson("/api/v1/products/{$this->product->id}/media/{$id}", ['main' => false])
        ->assertJsonValidationErrors(['main' => 'photo principale']);
    $this->patchJson("/api/v1/products/{$this->product->id}/media/{$id}", [])
        ->assertJsonValidationErrors('main');
});

it('deletes a photo and its variants, the next one becoming main', function () {
    $this->actingAs($this->admin);
    $first = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->json('id');
    $second = ($this->upload)(UploadedFile::fake()->image('b.jpg', 40, 40))->json('id');

    $this->deleteJson("/api/v1/products/{$this->product->id}/media/{$first}")->assertNoContent();

    expect(Media::query()->find($first))->toBeNull();
    Storage::disk('media')->assertMissing("{$first}/p0042.webp");
    Storage::disk('media')->assertMissing("{$first}/conversions/p0042-thumb.webp");
    Storage::disk('media')->assertExists("{$second}/conversions/p0042-thumb.webp");

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertJsonPath('photos.*.id', [$second])
        ->assertJsonPath('photos.0.is_main', true);
});

it('answers 404 for a photo of another product', function () {
    $this->actingAs($this->admin);
    $other = Product::factory()->create();
    $id = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40), product: $other)->json('id');

    expectProblem($this->patchJson("/api/v1/products/{$this->product->id}/media/{$id}", ['main' => true]), 404, 'NOT_FOUND');
    expectProblem($this->deleteJson("/api/v1/products/{$this->product->id}/media/{$id}"), 404, 'NOT_FOUND');
    expectProblem($this->deleteJson("/api/v1/products/{$this->product->id}/media/999"), 404, 'NOT_FOUND');

    expect(Media::query()->find($id))->not->toBeNull();
});

it('reserves photo management to product.manage', function (Role $role) {
    $this->actingAs($this->admin);
    $id = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->json('id');
    $this->actingAs(userWithRole($role));

    expectProblem(($this->upload)(UploadedFile::fake()->image('b.jpg', 40, 40)), 403, 'FORBIDDEN');
    expectProblem($this->postJson("/api/v1/products/{$this->product->id}/media", []), 403, 'FORBIDDEN');
    expectProblem($this->patchJson("/api/v1/products/{$this->product->id}/media/{$id}", ['main' => true]), 403, 'FORBIDDEN');
    expectProblem($this->deleteJson("/api/v1/products/{$this->product->id}/media/{$id}"), 403, 'FORBIDDEN');

    expect(Media::query()->count())->toBe(1);
})->with([Role::Adv, Role::Regional, Role::Client]);

it('lets an ADV granted product.manage manage photos (option)', function () {
    $adv = userWithRole(Role::Adv);
    $adv->givePermissionTo(Permission::ProductManage->value);
    $this->actingAs($adv);

    ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->assertCreated();
});

it('loads a catalogue page with photos and prices in a constant number of queries', function () {
    $this->actingAs($this->admin);
    $addProduct = function () {
        $product = Product::factory()->create();
        app(PriceBook::class)->setPrice($product, '900', BusinessClock::today(), null);
        app(PriceBook::class)->setPrice($product, '950', BusinessClock::today()->addMonth(), null);
        foreach (['a.jpg', 'b.jpg'] as $name) {
            ($this->upload)(UploadedFile::fake()->image($name, 40, 40), product: $product)->assertCreated();
        }

        return $product;
    };
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/products')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addProduct();
    $this->getJson('/api/v1/products')->assertOk(); // Préchauffage (permissions en cache).
    $withOne = $queries();
    $addProduct();
    $addProduct();
    $last = $addProduct();

    expect($queries())->toBe($withOne);
    $page = $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 5)->json('data');
    $listed = collect($page)->firstWhere('id', $last->id);
    expect($listed['photos'])->toHaveCount(2)
        ->and($listed['photos'][0]['is_main'])->toBeTrue()
        ->and($listed['price']['price_ht'])->toEqual(900)
        ->and($listed['next_price']['price_ht'])->toEqual(950);
});

it('shows photos to client accounts', function () {
    $this->actingAs($this->admin);
    $id = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->json('id');
    $this->actingAs(userWithRole(Role::Client));

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertJsonPath('photos.0.urls.medium', "/media/{$id}/conversions/p0042-medium.webp");
});

it('journals photo additions, main photo changes and deletions', function () {
    $this->actingAs($this->admin);
    $first = ($this->upload)(UploadedFile::fake()->image('a.jpg', 40, 40))->json('id');
    $second = ($this->upload)(UploadedFile::fake()->image('b.jpg', 40, 40))->json('id');
    $this->patchJson("/api/v1/products/{$this->product->id}/media/{$second}", ['main' => true])->assertOk();
    $this->deleteJson("/api/v1/products/{$this->product->id}/media/{$first}")->assertNoContent();

    $logs = AuditLog::query()
        ->where('subject_type', 'product')
        ->where('subject_id', $this->product->id)
        ->where('event', 'like', 'photo.%')
        ->orderBy('id')
        ->get();

    expect($logs->pluck('event')->all())->toBe(['photo.added', 'photo.added', 'photo.main', 'photo.deleted'])
        ->and($logs->pluck('log_name')->unique()->all())->toBe(['referentials'])
        ->and($logs->pluck('causer_id')->unique()->all())->toBe([$this->admin->id])
        ->and($logs[0]->description)->toBe('Photo produit ajoutée')
        ->and($logs[0]->properties['attributes'])->toEqual(['photo_id' => $first, 'is_main' => true]) // MySQL réordonne les clés JSON.
        ->and($logs[1]->properties['attributes'])->toEqual(['photo_id' => $second, 'is_main' => false])
        ->and($logs[2]->description)->toBe('Photo principale modifiée')
        ->and($logs[2]->properties['old'])->toBe(['main_photo_id' => $first])
        ->and($logs[2]->properties['attributes'])->toBe(['main_photo_id' => $second])
        ->and($logs[3]->description)->toBe('Photo produit supprimée')
        ->and($logs[3]->properties['old'])->toEqual(['photo_id' => $first, 'is_main' => false]);
});
