<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AiUpCommand extends Command
{
    protected $signature = 'pilo:ai-up';
    protected $description = "Démarre les conteneurs IA (paddleocr + ollama) via docker compose --profile ai";

    public function handle(): int
    {
        $this->info('Démarrage des conteneurs IA...');

        $dir = base_path();
        exec("cd {$dir} && docker compose --profile ai up -d paddleocr ollama 2>&1", $output, $code);

        foreach ($output as $line) {
            $this->line($line);
        }

        if ($code !== 0) {
            $this->error("Échec du démarrage (code {$code}).");
            return self::FAILURE;
        }

        $this->info('Health-check (max 60 s)...');
        $paddleUrl = env('PADDLEOCR_URL', 'http://paddleocr:8000');
        $ollamaUrl = env('OLLAMA_URL', 'http://ollama:11434');

        for ($i = 1; $i <= 20; $i++) {
            sleep(3);
            try {
                $pOk = Http::timeout(2)->get("{$paddleUrl}/health")->ok();
                $oOk = Http::timeout(2)->get("{$ollamaUrl}/api/version")->ok();
                if ($pOk && $oOk) {
                    $this->info('Services IA disponibles.');
                    return self::SUCCESS;
                }
            } catch (\Throwable) {
                // encore en démarrage
            }
            $this->line("  tentative {$i}/20...");
        }

        $this->error('Timeout : les services IA ne répondent pas après 60 s.');
        return self::FAILURE;
    }
}
