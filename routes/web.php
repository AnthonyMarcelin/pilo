<?php

use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\MedicationNoteController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('today')
        : redirect()->route('login');
});

Route::middleware(['auth', 'password.changed'])->group(function () {

    Route::get('/today', TodayController::class)->name('today');

    Route::get('/prescriptions', [PrescriptionController::class, 'index'])
        ->name('prescriptions.index');

    Route::get('/prescriptions/create', fn () => Inertia::render('Prescriptions/Create'))
        ->name('prescriptions.create');

    Route::get('/prescriptions/create/manual', [PrescriptionController::class, 'create'])
        ->name('prescriptions.create.manual');

    Route::post('/prescriptions', [PrescriptionController::class, 'store'])
        ->name('prescriptions.store');

    Route::get('/prescriptions/{prescription}', [PrescriptionController::class, 'show'])
        ->name('prescriptions.show');

    Route::post('/prescriptions/{prescription}/archive', [PrescriptionController::class, 'archive'])
        ->name('prescriptions.archive');

    Route::get('/prescriptions/{prescription}/image', [PrescriptionController::class, 'image'])
        ->name('prescriptions.image');

    // ── Scan IA ──────────────────────────────────────────────────────────────
    Route::post('/scans',                   [ScanController::class, 'store'])  ->name('scans.store');
    Route::get('/scans/{id}',               [ScanController::class, 'show'])   ->name('scans.show');
    Route::get('/scans/{id}/status',        [ScanController::class, 'status']) ->name('scans.status');
    Route::get('/scans/{id}/form',          [ScanController::class, 'form'])   ->name('scans.form');
    Route::get('/scans/{id}/image',         [ScanController::class, 'image'])  ->name('scans.image');

    Route::get('/medications', fn () => Inertia::render('Medications/Index'))
        ->name('medications.index');

    Route::post('/medications/{normalized}/note', [MedicationNoteController::class, 'upsert'])
        ->name('medications.note.upsert');

    Route::delete('/medications/{normalized}/note', [MedicationNoteController::class, 'destroy'])
        ->name('medications.note.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/password/setup', [PasswordSetupController::class, 'show'])
        ->name('password.setup');
    Route::post('/password/setup', [PasswordSetupController::class, 'update'])
        ->name('password.setup.update');
});

require __DIR__.'/auth.php';
