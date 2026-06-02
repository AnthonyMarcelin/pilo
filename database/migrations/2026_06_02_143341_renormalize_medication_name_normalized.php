<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $items = DB::table('prescription_items')
            ->select('id', 'medication_name_normalized')
            ->get();

        foreach ($items as $item) {
            $renormalized = $this->normalize($item->medication_name_normalized);
            if ($renormalized !== $item->medication_name_normalized) {
                DB::table('prescription_items')
                    ->where('id', $item->id)
                    ->update(['medication_name_normalized' => $renormalized]);
            }
        }
    }

    public function down(): void
    {
        // Irréversible sans stocker les anciennes valeurs
    }

    private function normalize(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/\s*\(.*?\)\s*/u', ' ', $n);
        $n = preg_replace('/\s+\d.*/u', '', $n);
        return trim($n);
    }
};
