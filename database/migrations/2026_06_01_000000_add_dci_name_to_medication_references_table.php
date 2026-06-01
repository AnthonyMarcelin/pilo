<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_references', function (Blueprint $table) {
            // Substance active (DCI/INN) extraite de CIS_COMPO_bdpm.txt, col 2.
            // Ex : "LÉVOTHYROXINE SODIQUE", "AMLODIPINE", "PAROXÉTINE".
            // Utilisée pour le matching OCR-nom (DCI) → BDPM quand le nom
            // commercial ne correspond pas directement.
            $table->string('dci_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('medication_references', function (Blueprint $table) {
            $table->dropColumn('dci_name');
        });
    }
};
