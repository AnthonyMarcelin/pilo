<?php

return [
    /*
     | Nombre de jours avant épuisement estimé du stock à partir duquel
     | un encart « pense à renouveler » s'affiche dans l'écran Aujourd'hui.
     | Configurable via ALERT_THRESHOLD_DAYS dans .env (défaut : 7).
     */
    'stock_alert_days' => (int) env('ALERT_THRESHOLD_DAYS', 7),

    // ── Mistral OCR ───────────────────────────────────────────────────────────
    // Clé API Mistral — JAMAIS committée. Générer sur console.mistral.ai.
    // Coût indicatif : ~0,001 $ par ordonnance (1 appel /v1/ocr structuré).
    // Note vie privée : l'image est envoyée aux serveurs Mistral (30j rétention
    // par défaut ; activer ZDR sur console.mistral.ai avant usage en prod).
    'mistral_api_key' => env('MISTRAL_API_KEY', ''),
];
