<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescription_scans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // pending → processing → done | failed
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])
                ->default('pending');

            $table->string('source_image_path')->nullable();

            // Brouillon JSON retourné par l'IA (null tant que scan non terminé)
            $table->json('draft')->nullable();

            // Message d'erreur en cas d'échec
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_scans');
    }
};
