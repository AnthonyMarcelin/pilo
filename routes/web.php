<?php

use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\MedicationNoteController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('today')
        : redirect()->route('login');
});

Route::middleware(['auth', 'password.changed'])->group(function () {

    Route::get('/today', function () {
        return Inertia::render('Today', [
            'todayLabel' => now()->locale('fr')->isoFormat('dddd D MMMM YYYY'),
        ]);
    })->name('today');

    Route::get('/prescriptions', fn () => Inertia::render('Prescriptions/Index'))
        ->name('prescriptions.index');

    Route::get('/prescriptions/create', fn () => Inertia::render('Prescriptions/Create'))
        ->name('prescriptions.create');

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
