<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Démarre les conteneurs IA (llama-server → paddleocr-vl → ollama) et attend
 * qu'ils soient tous disponibles.
 *
 * Ordre de démarrage : llama-server en premier (paddleocr-vl en dépend).
 * Health-check : 20 tentatives × 3 s = 60 s max.
 */
class AiUpCommand extends Command
{
    protected $signature   = 'pilo:ai-up';
    protected $description = 'Démarre les conteneurs IA (llama-server + paddleocr-vl + ollama) via docker compose --profile ai';

    public function handle(): int
    {
        $this->info('Démarrage des conteneurs IA...');

        $dir = escapeshellarg(base_path());
        exec("cd {$dir} && docker compose --profile ai up -d llama-server paddleocr-vl ollama 2>&1", $output, $code);

        foreach ($output as $line) {
            $this->line($line);
        }

        if ($code !== 0) {
            $this->error("Échec du démarrage (code {$code}).");
            return self::FAILURE;
        }

        $this->info('Health-check (max 60 s)…');

        $paddleUrl = config('pilo.paddleocr_url', 'http://paddleocr-vl:8000');
        $ollamaUrl = config('pilo.ollama_url',    'http://ollama:11434');
        $llamaUrl  = config('pilo.llama_url',     'http://llama-server:8111');

        for ($i = 1; $i <= 20; $i++) {
            sleep(3);
            try {
                // llama-server : GET /health → 200 quand le modèle est chargé
                $lOk = Http::timeout(3)->get("{$llamaUrl}/health")->ok();
                // paddleocr-vl : GET /health → {"status":"ok"}
                $pOk = Http::timeout(3)->get("{$paddleUrl}/health")->ok();
                // ollama : GET /api/version → {"version":"..."}
                $oOk = Http::timeout(3)->get("{$ollamaUrl}/api/version")->ok();

                if ($lOk && $pOk && $oOk) {
                    $this->info('Services IA disponibles.');
                    return self::SUCCESS;
                }
            } catch (\Throwable) {
                // encore en démarrage
            }
            $this->line("  tentative {$i}/20…");
        }

        $this->error('Timeout : les services IA ne répondent pas après 60 s.');
        return self::FAILURE;
    }
}
