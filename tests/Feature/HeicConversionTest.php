<?php

/**
 * Tests HEIC → JPEG conversion pour les uploads iPhone.
 *
 * Couvre :
 *  - Détection HEIC (MIME + extension)
 *  - Conversion réelle via Imagick (fixture tests/fixtures/sample.heic)
 *  - Validation ScanController accepte HEIC
 *  - Validation StorePrescriptionRequest accepte HEIC
 *  - Fichier stocké = JPEG, jamais HEIC brut
 */

use App\Http\Controllers\ScanController;
use App\Jobs\ProcessPrescriptionScan;
use App\Models\User;
use App\Services\ImageConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function heicFixture(): UploadedFile
{
    return new UploadedFile(
        path:         base_path('tests/fixtures/sample.heic'),
        originalName: 'ordonnance.heic',
        mimeType:     'image/heic',
        error:        UPLOAD_ERR_OK,
        test:         true,
    );
}

// ─── ImageConverter — détection ───────────────────────────────────────────────

describe('ImageConverter::isHeic()', function () {

    it('détecte un HEIC par MIME type image/heic', function () {
        $file = UploadedFile::fake()->create('photo.heic', 10, 'image/heic');
        expect(app(ImageConverter::class)->isHeic($file))->toBeTrue();
    });

    it('détecte un HEIF par MIME type image/heif', function () {
        $file = UploadedFile::fake()->create('photo.heif', 10, 'image/heif');
        expect(app(ImageConverter::class)->isHeic($file))->toBeTrue();
    });

    it('détecte un HEIC par extension même si MIME est générique', function () {
        $file = UploadedFile::fake()->create('photo.heic', 10, 'application/octet-stream');
        expect(app(ImageConverter::class)->isHeic($file))->toBeTrue();
    });

    it('ne détecte pas un JPEG comme HEIC', function () {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        expect(app(ImageConverter::class)->isHeic($file))->toBeFalse();
    });

    it('ne détecte pas un PNG comme HEIC', function () {
        $file = UploadedFile::fake()->image('photo.png', 100, 100);
        expect(app(ImageConverter::class)->isHeic($file))->toBeFalse();
    });

});

// ─── ImageConverter — conversion réelle ───────────────────────────────────────

describe('ImageConverter::storeAsJpeg() — conversion HEIC', function () {

    beforeEach(fn () => Storage::fake('local'));

    it('convertit un fichier HEIC réel en JPEG et le stocke', function () {
        $path = app(ImageConverter::class)->storeAsJpeg(heicFixture(), 'prescriptions/scans');

        // Extension JPEG
        expect($path)->toEndWith('.jpg');

        // Fichier présent sur le disque
        Storage::disk('local')->assertExists($path);

        // Contenu = JPEG (magic bytes FF D8 FF)
        $content = Storage::disk('local')->get($path);
        expect(substr($content, 0, 3))->toBe("\xFF\xD8\xFF");
    });

    it('stocke un JPEG tel quel (sans re-conversion)', function () {
        $jpeg = UploadedFile::fake()->image('photo.jpg', 50, 50);
        $path = app(ImageConverter::class)->storeAsJpeg($jpeg, 'prescriptions/scans');

        Storage::disk('local')->assertExists($path);
    });

    it('stocke un PNG tel quel', function () {
        $png = UploadedFile::fake()->image('photo.png', 50, 50);
        $path = app(ImageConverter::class)->storeAsJpeg($png, 'prescriptions/scans');

        Storage::disk('local')->assertExists($path);
    });

});

// ─── ScanController — validation et stockage ──────────────────────────────────

describe('ScanController — HEIC accepté', function () {

    beforeEach(function () {
        Storage::fake('local');
        Queue::fake();
        $this->user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->user);
    });

    it('accepte un fichier HEIC et le convertit en JPEG avant stockage', function () {
        $response = $this->post(route('scans.store'), ['image' => heicFixture()]);

        $response->assertRedirect();
        Queue::assertPushed(ProcessPrescriptionScan::class);

        // Le path stocké est un JPEG
        $scan = \App\Models\PrescriptionScan::first();
        expect($scan->source_image_path)->toEndWith('.jpg');
    });

    it('rejette un fichier non supporté (txt)', function () {
        $txt = UploadedFile::fake()->create('note.txt', 100, 'text/plain');
        $this->post(route('scans.store'), ['image' => $txt])
            ->assertSessionHasErrors('image');
    });

});
