<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_item_phases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prescription_item_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Ordre du palier (1-based). Ex : palier 1 = "3 cp / 3j", palier 2 = "2 cp / 5j".
            $table->unsignedSmallInteger('phase_order');

            // Durée du palier en jours (obligatoire — un palier sans durée = intake_type autre).
            $table->unsignedSmallInteger('duration_days');

            $table->decimal('morning', 5, 2)->nullable();
            $table->decimal('noon',    5, 2)->nullable();
            $table->decimal('evening', 5, 2)->nullable();
            $table->decimal('bedtime', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['prescription_item_id', 'phase_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_item_phases');
    }
};
