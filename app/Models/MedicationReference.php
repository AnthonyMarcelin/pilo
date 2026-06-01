<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Phase 6 pilo:import-bdpm fait un updateOrCreate sur le champ cis.
// Ces données de seed seront remplacées par l'import complet BDPM sans créer de doublons.
#[Fillable(['cis', 'cip13', 'name', 'dci_name', 'presentation_label', 'units_per_box', 'indication'])]
class MedicationReference extends Model {}
