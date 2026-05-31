<?php

return [
    /*
     | Nombre de jours avant épuisement estimé du stock à partir duquel
     | un encart « pense à renouveler » s'affiche dans l'écran Aujourd'hui.
     | Configurable via ALERT_THRESHOLD_DAYS dans .env (défaut : 7).
     */
    'stock_alert_days' => (int) env('ALERT_THRESHOLD_DAYS', 7),

    // ── Services IA (à la demande — profil "ai" docker compose) ─────────────
    'paddleocr_url' => env('PADDLEOCR_URL', 'http://paddleocr-vl:8000'),
    'ollama_url'    => env('OLLAMA_URL',    'http://ollama:11434'),
    'llama_url'     => env('LLAMA_URL',     'http://llama-server:8111'),
    'ollama_model'  => env('OLLAMA_MODEL',  'qwen2.5:1.5b-instruct'),

    // Délai (secondes) après le dernier scan avant arrêt auto des conteneurs IA
    'ai_idle_seconds' => (int) env('AI_IDLE_SECONDS', 300),

    // Provider OCR : 'local' = LocalOcrProvider (seul driver autorisé — CLAUDE.md §2.6)
    'ocr_provider' => env('OCR_PROVIDER', 'local'),
];
