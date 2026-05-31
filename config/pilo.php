<?php

return [
    /*
     | Nombre de jours avant épuisement estimé du stock à partir duquel
     | un encart « pense à renouveler » s'affiche dans l'écran Aujourd'hui.
     | Configurable via ALERT_THRESHOLD_DAYS dans .env (défaut : 7).
     */
    'stock_alert_days' => (int) env('ALERT_THRESHOLD_DAYS', 7),

    // ── Services IA (permanents — démarrés avec docker compose up) ─────────────
    'paddleocr_url' => env('PADDLEOCR_URL', 'http://paddleocr-vl:8000'),
    'ollama_url'    => env('OLLAMA_URL',    'http://ollama:11434'),
    // qwen2.5:3b-instruct recommandé (anti-hallucination supérieure sur blocs vides).
    // qwen2.5:1.5b-instruct acceptable si la RAM est insuffisante pour le 3B (~1 Go économisé).
    // Ne pas utiliser 1.5B sans disposer des blocs VL réels : en blocs vides il hallucine
    // des prescriptions complètes depuis les exemples few-shot (testé 2026-05-31).
    'ollama_model'  => env('OLLAMA_MODEL',  'qwen2.5:3b-instruct'),

    // Provider OCR : 'local' = LocalOcrProvider (seul driver autorisé)
    'ocr_provider' => env('OCR_PROVIDER', 'local'),
];
