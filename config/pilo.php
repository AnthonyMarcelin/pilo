<?php

return [
    /*
     | Nombre de jours avant épuisement estimé du stock à partir duquel
     | un encart « pense à renouveler » s'affiche dans l'écran Aujourd'hui.
     | Configurable via ALERT_THRESHOLD_DAYS dans .env (défaut : 7).
     */
    'stock_alert_days' => (int) env('ALERT_THRESHOLD_DAYS', 7),

    // ── Driver OCR ────────────────────────────────────────────────────────────
    // 'local'   → LocalOcrProvider  : PaddleOCR + Ollama (auto-hébergé, zéro cloud)
    // 'mistral' → MistralOcrProvider: API Mistral OCR + Chat (cloud, zéro RAM locale)
    //
    // Changer de driver = modifier OCR_DRIVER dans .env puis `docker compose restart queue`.
    'ocr_driver'       => env('OCR_DRIVER', 'local'),

    // ── Mistral API (driver 'mistral') ────────────────────────────────────────
    // Clé API Mistral — JAMAIS committée. Générer sur console.mistral.ai.
    // Coût indicatif : ~0,001 $ par ordonnance (OCR + structuration).
    // Note vie privée : l'image est envoyée aux serveurs Mistral (30j rétention
    // par défaut ; activer ZDR sur console.mistral.ai pour les données de santé).
    'mistral_api_key'  => env('MISTRAL_API_KEY', ''),

    // ── Services IA locaux (driver 'local') ──────────────────────────────────
    'paddleocr_url' => env('PADDLEOCR_URL', 'http://paddleocr-vl:8000'),
    'ollama_url'    => env('OLLAMA_URL',    'http://ollama:11434'),
    'ollama_model'  => env('OLLAMA_MODEL',  'qwen2.5:7b-instruct-q2_K'),
];
