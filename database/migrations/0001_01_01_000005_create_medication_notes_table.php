<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Même normalisation que prescription_items.medication_name_normalized.
            // Clé de persistance : survit aux renouvellements d'ordonnance.
            $table->string('medication_name_normalized');
            // Note saisie par l'utilisatrice — JAMAIS générée par IA.
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'medication_name_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_notes');
    }
};
