<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── prescriptions ────────────────────────────────────────
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('prescriber_name')->nullable();
            $table->date('prescribed_at')->nullable();
            // source_type: 'scan' | 'manual'
            $table->enum('source_type', ['scan', 'manual']);
            $table->string('source_image_path')->nullable();
            // status: 'active' | 'terminated' | 'archived'
            $table->enum('status', ['active', 'terminated', 'archived'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ─── prescription_items ───────────────────────────────────
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();

            $table->string('medication_name');
            // Normalised name (lowercase, no dosage) — dedup key + medication_notes join
            $table->string('medication_name_normalized')->index();
            $table->string('dosage')->nullable();

            // intake_type: 'fixe' | 'si_besoin' | 'autre'
            $table->enum('intake_type', ['fixe', 'si_besoin', 'autre']);

            // Doses par moment — valeurs de la PHASE 1 (ou unique pour mono-phase)
            // Sources of truth for DailyRegimen: prescription_item_phases
            $table->decimal('morning', 5, 2)->nullable();
            $table->decimal('noon',    5, 2)->nullable();
            $table->decimal('evening', 5, 2)->nullable();
            $table->decimal('bedtime', 5, 2)->nullable();

            // si_besoin only
            $table->string('condition')->nullable();
            $table->decimal('max_per_day', 5, 2)->nullable();

            // TOUJOURS rempli — filet de sécurité indépendant de la structure
            $table->text('posologie_brute');

            // Durée totale = sum(prescription_item_phases.duration_days) pour fixe dégressive
            $table->integer('duration_days')->nullable();
            $table->integer('qsp_days')->nullable();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();    // calculée, stockée pour requêtes

            // Stock
            $table->decimal('stock_units', 8, 2)->nullable();
            $table->integer('boxes_count')->nullable();
            $table->integer('units_per_box')->nullable(); // from BDPM
            $table->string('cip_code')->nullable();
            $table->date('stock_end_date')->nullable();  // estimée (~)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};
