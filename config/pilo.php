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
    // qwen2.5:7b-instruct : modèle retenu après tests sur ordonnance réelle.
    //   Le 3B montrait hallucinations (prescripteur inventé), fuite de raisonnement
    //   en anglais dans les champs JSON, labels de pied de page traités comme
    //   médicaments, et paliers dégressifs éclatés en doublons. (testé 2026-06-01)
    // RAM : 7B Q4_K_M ≈ 4.5 Go dans Ollama. Pendant la normalisation, llama-server
    //   (1.8-2.2 Go) n'est plus nécessaire (OCR terminé). RAM totale pic ≈ 7-8 Go.
    //   Si OOM : augmenter la RAM VM (Proxmox ballooning) ou passer à 7b Q2_K (~3 Go).
    // qwen2.5:3b-instruct : fallback si RAM insuffisante pour le 7B.
    'ollama_model'  => env('OLLAMA_MODEL',  'qwen2.5:7b-instruct'),

    // Provider OCR : 'local' = LocalOcrProvider (seul driver autorisé)
    'ocr_provider' => env('OCR_PROVIDER', 'local'),
];
