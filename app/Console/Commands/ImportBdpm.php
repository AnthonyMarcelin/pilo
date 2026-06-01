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

    protected $description = 'Importe le référentiel BDPM (CIS_CIP requis ; SMR, GENER, COMPO optionnels)';

    public function handle(BdpmParser $parser): int
    {
        $path = rtrim($this->option('path') ?? storage_path('app/bdpm'), '/');
        $dry  = (bool) $this->option('dry-run');

        $this->line("Répertoire BDPM : {$path}");

        // ── 1. Seul fichier vraiment requis : CIS_CIP (base de la boucle) ─────
        $cipPath = "{$path}/CIS_CIP_bdpm.txt";
        if (! file_exists($cipPath)) {
            $this->error("Fichier requis manquant : {$cipPath}");
            $this->line('  Télécharge-le depuis : https://base-donnees-publique.medicaments.gouv.fr/telechargement.php');
            return Command::FAILURE;
        }

        // ── 2. Parsing ────────────────────────────────────────────────────────

        // Obligatoire
        $this->info('Lecture CIS_CIP…');
        $cip = $parser->parseCisCip($cipPath);
        $this->line(sprintf('  → %d entrées CIS/CIP', count($cip)));

        // Optionnel — indications officielles (libellé SMR)
        $smr      = [];
        $smrPath  = "{$path}/CIS_HAS_SMR_bdpm.txt";
        if (file_exists($smrPath)) {
            $this->info('Lecture CIS_HAS_SMR…');
            $smr = $parser->parseCisHasSMR($smrPath);
            $this->line(sprintf('  → %d indications SMR', count($smr)));
        } else {
            $this->warn('CIS_HAS_SMR_bdpm.txt absent — indication sera null pour tous les médicaments.');
        }

        // Optionnel — lien générique → originator (hérite de l'indication SMR)
        $gener     = [];
        $generPath = "{$path}/CIS_GENER_bdpm.txt";
        if (file_exists($generPath)) {
            $this->info('Lecture CIS_GENER…');
            $gener = $parser->parseCisGener($generPath);
            $this->line(sprintf('  → %d liens générique→originator', count($gener)));
        } else {
            $this->warn('CIS_GENER_bdpm.txt absent — les génériques n\'hériteront pas de l\'indication de leur référent.');
        }

        // Optionnel — substance active (DCI) pour le matching par nom
        $compo     = [];
        $compoPath = "{$path}/CIS_COMPO_bdpm.txt";
        if (file_exists($compoPath)) {
            $this->info('Lecture CIS_COMPO…');
            $compo = $parser->parseCisCompo($compoPath);
            $this->line(sprintf('  → %d substances actives (DCI)', count($compo)));
        } else {
            $this->warn('CIS_COMPO_bdpm.txt absent — dci_name non renseigné (matching DCI désactivé).');
        }

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
            // Indication : propre d'abord, sinon héritée de l'originator (générique).
            // $gener[$cis] peut pointer vers un CIS absent de $smr → isset() obligatoire
            // pour éviter "Undefined array key" sur $smr[$orig].
            $orig       = $gener[$cis] ?? null;
            $indication = $smr[$cis] ?? ($orig !== null ? ($smr[$orig] ?? null) : null);

            MedicationReference::updateOrCreate(
                ['cis' => $cis],
                [
                    'cip13'              => $data['cip13'],
                    'name'               => $data['name'],
                    'dci_name'           => $compo[$cis] ?? null,
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
