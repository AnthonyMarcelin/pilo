<?php

namespace App\Services\Ocr;

/**
 * Lancée quand l'API Mistral renvoie HTTP 402 (crédits insuffisants)
 * ou une réponse 429 indiquant un dépassement de quota de facturation.
 *
 * Distinguée de OcrException pour afficher un message spécifique dans l'UI
 * et éviter la saisie manuelle comme seule issue (rechargement de compte nécessaire).
 */
class MistralCreditException extends OcrException {}
