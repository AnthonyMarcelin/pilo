<?php

namespace App\Console\Commands;

use App\Models\MedicationReference;
use App\Services\Bdpm\BdpmParser;
use Illuminate\Console\Command;

/**
 * Importe le référentiel BDPM dans medication_references.
 *
 * Usage :
 *   php artisan pilo:import-bdpm
 *   php artisan pilo:import-bdpm --path=/data/bdpm
 *
 * Les 3 fichiers doivent être présents dans le répertoire cible :
 *   CIS_CIP_bdpm.txt, CIS_HAS_SMR_bdpm.txt, CIS_GENER_bdpm.txt
 *
 * Voir CLAUDE.md §3 et SPEC §9.
 */
class ImportBdpm extends Command
{
    protected $signature = 'pilo:import-bdpm
                            {--path= : Chemin vers les fichiers BDPM (défaut : storage/app/bdpm)}
                            {--dry-run : Affiche les stats sans écrire en base}';

    protected $description = 'Importe le référentiel BDPM (CIS_CIP + CIS_HAS_SMR + CIS_GENER)';

    public function handle(BdpmParser $parser): int
    {
        $path = rtrim($this->option('path') ?? storage_path('app/bdpm'), '/');
        $dry  = (bool) $this->option('dry-run');

        $this->line("Répertoire BDPM : {$path}");

        // ── 1. Vérification de la présence des fichiers ───────────────────────
        $required = ['CIS_CIP_bdpm.txt', 'CIS_HAS_SMR_bdpm.txt', 'CIS_GENER_bdpm.txt'];
        foreach ($required as $file) {
            if (! file_exists("{$path}/{$file}")) {
                $this->error("Fichier manquant : {$path}/{$file}");
                return Command::FAILURE;
            }
        }

        // ── 2. Parsing ────────────────────────────────────────────────────────
        $this->info('Lecture CIS_CIP…');
        $cip = $parser->parseCisCip("{$path}/CIS_CIP_bdpm.txt");
        $this->line(sprintf('  → %d entrées CIS/CIP', count($cip)));

        $this->info('Lecture CIS_HAS_SMR…');
        $smr = $parser->parseCisHasSMR("{$path}/CIS_HAS_SMR_bdpm.txt");
        $this->line(sprintf('  → %d indications SMR', count($smr)));

        $this->info('Lecture CIS_GENER…');
        $gener = $parser->parseCisGener("{$path}/CIS_GENER_bdpm.txt");
        $this->line(sprintf('  → %d liens générique→originator', count($gener)));

        if ($dry) {
            $this->warn('[dry-run] Aucune écriture en base.');
            return Command::SUCCESS;
        }

        // ── 3. Upsert en base ─────────────────────────────────────────────────
        $this->info('Mise à jour en base…');
        $bar = $this->output->createProgressBar(count($cip));
        $bar->start();

        $upserted = 0;

        foreach ($cip as $cis => $data) {
            // Indication : propre d'abord, sinon celle de l'originator (générique)
            $indication = $smr[$cis]
                ?? ($gener[$cis] ? ($smr[$gener[$cis]] ?? null) : null);

            MedicationReference::updateOrCreate(
                ['cis' => $cis],
                [
                    'cip13'              => $data['cip13'],
                    'name'               => $data['name'],
                    'presentation_label' => $data['presentation_label'],
                    'units_per_box'      => $data['units_per_box'],
                    'indication'         => $indication,
                ],
            );

            $upserted++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Import terminé : %d médicaments mis à jour.', $upserted));

        return Command::SUCCESS;
    }
}
