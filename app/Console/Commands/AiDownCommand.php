<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AiDownCommand extends Command
{
    protected $signature   = 'pilo:ai-down';
    protected $description = 'Arrête les conteneurs IA (llama-server + paddleocr-vl + ollama) pour libérer la RAM';

    public function handle(): int
    {
        $this->info('Arrêt des conteneurs IA…');

        $dir = escapeshellarg(base_path());
        exec("cd {$dir} && docker compose --profile ai stop llama-server paddleocr-vl ollama 2>&1", $output, $code);

        foreach ($output as $line) {
            $this->line($line);
        }

        if ($code !== 0) {
            $this->error("Échec de l'arrêt (code {$code}).");
            return self::FAILURE;
        }

        $this->info('Conteneurs IA arrêtés — RAM libérée.');
        return self::SUCCESS;
    }
}
