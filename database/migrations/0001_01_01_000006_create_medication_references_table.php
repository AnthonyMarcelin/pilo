<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_references', function (Blueprint $table) {
            $table->id();
            // CIS code — clé naturelle BDPM, utilisée pour UPSERT à l'import
            $table->string('cis')->unique()->index();
            $table->string('cip13')->nullable();
            $table->string('name')->index();
            $table->text('presentation_label')->nullable();
            $table->integer('units_per_box')->nullable();
            // Indication officielle : libelle_SMR nettoyé (entités HTML → texte, HTML entities decoded).
            // Couverture : 100% originators via CIS_HAS_SMR_bdpm.txt.
            // Génériques : jointure via CIS_GENER (indication de l'originator).
            // Phase 6 pilo:import-bdpm fera un updateOrCreate sur cis — pas de doublon.
            $table->text('indication')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_references');
    }
};
