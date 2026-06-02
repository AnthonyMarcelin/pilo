<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP vers l'API Mistral Chat (POST /v1/chat/completions).
 *
 * Rôle : markdown OCR → JSON structuré conforme à notre schéma de prescription.
 * Utilise mistral-small-latest avec response_format JSON Schema pour le constrained
 * decoding (même principe que OllamaClient avec le paramètre `format`).
 *
 * Le prompt reprend les RÈGLES 1-10 de OllamaClient, adaptées pour le chat API :
 * le markdown Mistral OCR remplace les blocs PaddleOCR comme source de données.
 */
class MistralChatClient
{
    private const ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';
    private const MODEL    = 'mistral-small-latest';
    private const TIMEOUT  = 120; // Structuration sur ordonnance complexe

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * Normalise le markdown OCR en tableau PHP conforme au schéma de prescription.
     *
     * @param  string  $markdown  Texte OCR retourné par MistralOcrClient.
     * @return array   JSON décodé (même structure qu'OllamaClient::normalize()).
     *
     * @throws MistralCreditException  Si les crédits sont épuisés.
     * @throws OcrException            Pour toute autre erreur.
     */
    public function normalize(string $markdown): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken($this->apiKey)
                ->post(self::ENDPOINT, [
                    'model'           => self::MODEL,
                    'temperature'     => 0.0,
                    'random_seed'     => 42,
                    'response_format' => [
                        'type'        => 'json_schema',
                        'json_schema' => [
                            'name'   => 'prescription',
                            'strict' => true,
                            'schema' => $this->prescriptionSchema(),
                        ],
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user',   'content' => $markdown],
                    ],
                ]);
        } catch (\Throwable $e) {
            throw new OcrException("MistralChatClient : service inaccessible — {$e->getMessage()}", 0, $e);
        }

        $this->handleHttpError($response);

        $raw = $response->json('choices.0.message.content', '');

        Log::info('[MistralChatClient] Structuration terminée', [
            'model'       => self::MODEL,
            'input_chars' => mb_strlen($markdown),
            'output_len'  => strlen($raw),
        ]);

        return $this->parseJsonDefensively($raw);
    }

    /**
     * Schéma JSON de prescription — identique à OllamaClient::prescriptionSchema().
     * Définit le contrat de sortie pour le constrained decoding Mistral.
     */
    private function prescriptionSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['items'],
            'properties' => [
                'prescriber_name' => ['type' => ['string', 'null']],
                'prescribed_at'   => ['type' => ['string', 'null']],
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['medication_name', 'intake_type', 'posologie_brute'],
                        'properties' => [
                            'medication_name' => ['type' => 'string'],
                            'dosage'          => ['type' => ['string', 'null']],
                            'intake_type'     => ['type' => 'string', 'enum' => ['fixe', 'si_besoin', 'autre']],
                            'morning'         => ['type' => ['number', 'null']],
                            'noon'            => ['type' => ['number', 'null']],
                            'evening'         => ['type' => ['number', 'null']],
                            'bedtime'         => ['type' => ['number', 'null']],
                            'condition'       => ['type' => ['string', 'null']],
                            'max_per_day'     => ['type' => ['number', 'null']],
                            'duration_days'   => ['type' => ['integer', 'null']],
                            'qsp_days'        => ['type' => ['integer', 'null']],
                            'posologie_brute' => ['type' => 'string'],
                            'phases' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'required'   => ['duration_days'],
                                    'properties' => [
                                        'duration_days' => ['type' => 'integer'],
                                        'morning'       => ['type' => ['number', 'null']],
                                        'noon'          => ['type' => ['number', 'null']],
                                        'evening'       => ['type' => ['number', 'null']],
                                        'bedtime'       => ['type' => ['number', 'null']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Prompt système d'extraction — reprend les RÈGLES 1-10 de OllamaClient,
     * adaptées pour une entrée markdown (Mistral OCR) plutôt que blocs PaddleOCR.
     */
    private function systemPrompt(): string
    {
        return <<<PROMPT
Tu es un pharmacien assistant qui extrait les données d'une ordonnance médicale en JSON structuré.
L'entrée est le texte OCR de l'ordonnance au format markdown.

═══════════ RÈGLES FONDAMENTALES ═══════════

RÈGLE 0 — FORMAT DE RÉPONSE (absolue) :
Réponds UNIQUEMENT avec un objet JSON valide. Aucun texte avant ou après le JSON.
Si une info est absente → null. Jamais une phrase d'explication.

RÈGLE 1 — COMPLÉTUDE (erreur médicale si non respectée) :
Extrais ABSOLUMENT TOUS les médicaments présents dans le texte, sans exception.
Si l'ordonnance contient N médicaments, ton JSON doit contenir N items.

RÈGLE 2 — ANTI-LABELS (critique) :
Ignore les labels de formulaire : "Etablissement", "N° FINESS", "Prescripteur",
"N° RPPS", "Adresse", "Code postal", "Allergies", "Mutuelle", "N° Sécurité sociale",
"Signature", "Cachet". Ce ne sont pas des médicaments.

RÈGLE 3 — ANTI-INVENTION (sécurité absolue) :
Tout champ absent = null. N'invente jamais un médicament, un dosage, une posologie.
prescriber_name = nom du médecin TEL QU'ÉCRIT. null si absent.

RÈGLE 4 — medication_name :
Nom du médicament seul, SANS la concentration.
Correct : "Amlodipine". Incorrect : "Amlodipine 5 mg" (le "5 mg" va dans dosage).

RÈGLE 5 — dosage (TOUJOURS extraire si présent) :
La concentration/teneur telle qu'écrite (quantité + unité).
MÊME si accolé au nom ("Amlodipine 5 mg" → name:"Amlodipine" dosage:"5 mg").
MÊME si en texte joint ("Diazépam comprimé 5mg" → dosage:"5 mg").
null UNIQUEMENT si aucune concentration ne figure nulle part pour ce médicament.

RÈGLE 6 — intake_type :
- "fixe"     : horaire régulier (matin/midi/soir/coucher).
- "si_besoin": conditionnel (si douleur, si fièvre, PRN).
- "autre"    : irrégulier non planifiable (1 jour sur 2, hebdomadaire…).

RÈGLE 7 — morning / noon / evening / bedtime :
Nombre d'unités par prise. null si intake_type ≠ "fixe" OU si phases[] non vide.

RÈGLE 8 — phases[] (PALIERS DÉGRESSIFS) — ERREUR CRITIQUE si non respectée :
Un médicament à posologie dégressive = UN SEUL item avec phases[] rempli.
INTERDIT : créer deux items distincts pour le même médicament (un pour "7j" et un pour "15j").
INTERDIT : mettre morning/noon/evening/bedtime dans l'item parent quand phases[] non vide.
Signaux : "puis", "ensuite", "progressivement", "réduire à", durées successives.
→ Un objet par segment : {"duration_days": N, "morning": X, "noon": Y, "evening": Z, "bedtime": W}.
→ phases[] non vide → morning/noon/evening/bedtime de l'item PARENT = null.

RÈGLE 9 — posologie_brute : Copier VERBATIM. Toujours rempli.

RÈGLE 10 — duration_days / qsp_days : entiers. Dates : YYYY-MM-DD.

═══════════ EXEMPLES ═══════════

EXEMPLE 1 — Simple :
Texte : "Dr. Didier Delhaye / 15/03/2026\nAmoxicilline 500 mg 1 gélule matin midi soir 7 jours"
→ {"prescriber_name":"Dr. Didier Delhaye","prescribed_at":"2026-03-15","items":[{"medication_name":"Amoxicilline","dosage":"500 mg","intake_type":"fixe","morning":1,"noon":1,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":7,"qsp_days":null,"posologie_brute":"1 gélule matin midi soir 7 jours","phases":[]}]}

EXEMPLE 2 — Palier dégressif (1 seul item) :
Texte : "Paroxétine 20 mg 2 cp/j pendant 7j PUIS 1 cp/j pendant 15j"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Paroxétine","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":22,"qsp_days":null,"posologie_brute":"2 cp/j pendant 7j PUIS 1 cp/j pendant 15j","phases":[{"duration_days":7,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":15,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}

EXEMPLE 3 — Corticoïde dégressif 3 paliers :
Texte : "Prednisolone 20 mg 3 comprimés/jour 5 jours PUIS 2 comprimés/jour 5 jours PUIS 1 comprimé/jour 5 jours"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Prednisolone","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":15,"qsp_days":null,"posologie_brute":"3 comprimés/jour 5 jours PUIS 2 comprimés/jour 5 jours PUIS 1 comprimé/jour 5 jours","phases":[{"duration_days":5,"morning":3,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}

EXEMPLE 4 — Si besoin :
Texte : "Paracétamol 1000 mg si douleur max 4/j"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Paracétamol","dosage":"1000 mg","intake_type":"si_besoin","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":"si douleur","max_per_day":4,"duration_days":null,"qsp_days":null,"posologie_brute":"si douleur max 4/j","phases":[]}]}
PROMPT;
    }

    private function parseJsonDefensively(string $raw): array
    {
        $clean = trim($raw);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = trim($m[1]);
        }

        if ($clean === '') {
            throw new OcrException('MistralChatClient : réponse vide.');
        }

        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            throw new OcrException('MistralChatClient : JSON invalide — ' . json_last_error_msg());
        }

        return $decoded;
    }

    private function handleHttpError(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body   = $response->json('message') ?? $response->body();

        if ($status === 402 || ($status === 422 && str_contains(strtolower((string) $body), 'quota'))) {
            throw new MistralCreditException(
                'Crédits Mistral insuffisants. Rechargez votre compte sur console.mistral.ai.'
            );
        }

        if ($status === 429) {
            throw new OcrException('Limite de requêtes Mistral atteinte. Réessayez dans quelques secondes.');
        }

        if ($status === 401) {
            throw new OcrException('Clé API Mistral invalide. Vérifiez MISTRAL_API_KEY dans .env.');
        }

        throw new OcrException("MistralChatClient : erreur HTTP {$status} — {$body}");
    }
}
