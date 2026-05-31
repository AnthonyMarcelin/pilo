<?php
/**
 * Test Ollama réel — normalisation JSON sur blocs OCR simulés.
 *
 * Ce script appelle Ollama DIRECTEMENT (pas de mock) avec les blocs OCR
 * qui représentent ce que paddleocr-vl retournerait pour chaque image du golden set.
 *
 * Testé depuis le conteneur app : php tests/golden_set_ollama_test.php
 */

$ollamaUrl = 'http://ollama:11434';
$model     = 'qwen2.5:1.5b-instruct';

// ─── Schema Ollama (identique à OllamaClient::prescriptionSchema) ─────────────

$schema = [
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

// ─── Prompt Ollama (identique à OllamaClient::buildPrompt) ───────────────────

function buildPrompt(string $blockText): string {
    return <<<PROMPT
Tu es un assistant pharmaceutique. Tu normalises les blocs OCR d'une ordonnance vers un JSON structuré.

RÈGLES :
1. NE JAMAIS INVENTER : utilise null si absent ou incertain.
2. posologie_brute : COPIER VERBATIM le texte OCR de la posologie, sans résumer.
3. intake_type : "fixe" si horaire régulier (matin/midi/soir/coucher), "si_besoin" si sur indication/PRN, "autre" sinon.
4. morning/noon/evening/bedtime : nombre de prises (ex: 1, 2, 0.5). null si non spécifié ou si phases non vide.
5. phases : remplir UNIQUEMENT si la posologie est dégressive (plusieurs paliers avec durées distinctes).
   - Si phases non vide → morning/noon/evening/bedtime de l'item DOIVENT être null (les phases priment).
   - Si dose simple ET schéma par paliers pour le MÊME médicament → les paliers priment, ignorer la dose simple.
6. duration_days : durée totale en jours (nombre entier). qsp_days si "QSP X jours/mois".
7. Date format : YYYY-MM-DD.

EXEMPLE :
Blocs : "Amoxicilline 500 mg / 1 gélule matin midi soir pendant 7 jours / Dr. Bernard / 15/03/2026"
→ {"prescriber_name":"Dr. Bernard","prescribed_at":"2026-03-15","items":[{"medication_name":"Amoxicilline","dosage":"500 mg","intake_type":"fixe","morning":1,"noon":1,"evening":1,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":7,"qsp_days":null,"posologie_brute":"1 gélule matin midi soir pendant 7 jours","phases":[]}]}

EXEMPLE DÉGRESSIF :
Blocs : "Prednisolone 20 mg / 2 cp matin 5j puis 1 cp matin 5j puis arrêt"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Prednisolone","dosage":"20 mg","intake_type":"fixe","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":null,"max_per_day":null,"duration_days":null,"qsp_days":null,"posologie_brute":"2 cp matin 5j puis 1 cp matin 5j puis arrêt","phases":[{"duration_days":5,"morning":2,"noon":null,"evening":null,"bedtime":null},{"duration_days":5,"morning":1,"noon":null,"evening":null,"bedtime":null}]}]}

EXEMPLE SI BESOIN :
Blocs : "Paracétamol 1000 mg / si douleur, max 4 comprimés/jour"
→ {"prescriber_name":null,"prescribed_at":null,"items":[{"medication_name":"Paracétamol","dosage":"1000 mg","intake_type":"si_besoin","morning":null,"noon":null,"evening":null,"bedtime":null,"condition":"si douleur","max_per_day":4,"duration_days":null,"qsp_days":null,"posologie_brute":"si douleur, max 4 comprimés/jour","phases":[]}]}

BLOCS OCR DE L'ORDONNANCE (ordonnés par position de lecture) :
{$blockText}
PROMPT;
}

// ─── Appel Ollama ─────────────────────────────────────────────────────────────

function callOllama(string $url, string $model, string $prompt, array $schema): array {
    $payload = json_encode([
        'model'  => $model,
        'prompt' => $prompt,
        'format' => $schema,
        'stream' => false,
    ]);

    $ch = curl_init("{$url}/api/generate");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $raw === false) {
        throw new RuntimeException("Ollama HTTP {$code}");
    }

    $resp = json_decode($raw, true);
    $json = $resp['response'] ?? '';

    // Strip ```json fences
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $json, $m)) {
        $json = trim($m[1]);
    }

    return json_decode($json, true) ?? [];
}

// ─── Golden Set ───────────────────────────────────────────────────────────────

$cases = [];

// ── CAS 1 : Ordonnance imprimée standard ─────────────────────────────────────
// Blocs simulant la sortie paddleocr-vl sur ordonnance_imprimee.jpg
$cases['ordonnance_imprimee'] = [
    'description' => 'Ordonnance imprimée : Levothyrox + Gabapentine + Prednisone (dégressive) + Diazépam (si besoin)',
    'expected'    => [
        'nb_items'   => 4,
        'types'      => ['fixe', 'fixe', 'fixe', 'si_besoin'],
        'has_phases' => [false, false, true, false],  // Prednisone a 2 phases
    ],
    'blocks' => <<<BLOCKS
[1|text] Dr. Martin Paul, Médecin Généraliste, 12 rue de la République — 13001 Marseille
[2|text] Date : 01/06/2026  — Patient : Mme D.
[3|text] ORDONNANCE
[4|text] Levothyrox 100 µg, comprimé sécable
[5|text] 1 comprimé le matin à jeun, 30 min avant le petit-déjeuner — Durée : 365 jours — QSP 3 mois
[6|text] Gabapentine 100 mg, gélule
[7|text] 1 gélule matin, 1 gélule midi, 2 gélules soir — Durée : 90 jours
[8|text] Prednisone 20 mg, comprimé
[9|text] 2 comprimés le matin pendant 5 jours puis 1 comprimé le matin pendant 5 jours puis arrêt
[10|text] Diazépam 10 mg, comprimé
[11|text] Si besoin, en cas d'anxiété aiguë — 1 comprimé par prise, maximum 1 comprimé par jour
[12|text] Dr. Martin Paul
BLOCKS,
];

// ── CAS 2 : Ordonnance dégressive Paroxétine ─────────────────────────────────
$cases['ordonnance_degressive'] = [
    'description' => 'Paroxétine dégressive 3 paliers (dose générale + schéma détaillé)',
    'expected'    => [
        'nb_items'   => 1,
        'types'      => ['fixe'],
        'has_phases' => [true],   // 3 paliers : 2cp×7j, 1cp×15j, arrêt
    ],
    'blocks' => <<<BLOCKS
[1|text] Dr. Lefebvre Sophie, Psychiatre — 8 avenue Gambetta — 75020 Paris
[2|text] Date : 29/05/2026 — Patient : Mme D. — ORDONNANCE SÉCURISÉE
[3|text] Paroxétine 20 mg, comprimé pelliculé
[4|text] 2 comprimés le matin pendant 28 jours (schéma dégressif ci-dessous)
[5|text] Schéma posologique :
[6|text] Phase 1 : 2 comprimés/matin × 7 jours
[7|text] Phase 2 : 1 comprimé/matin × 15 jours
[8|text] Phase 3 : arrêt
[9|text] Durée totale : 22 jours
[10|text] À administrer sous surveillance médicale. Ne pas interrompre brutalement.
[11|text] Dr. Lefebvre Sophie
BLOCKS,
];

// ── CAS 3 : Manuscrit illisible simulé ───────────────────────────────────────
$cases['ordonnance_manuscrite'] = [
    'description' => 'Ordonnance manuscrite — blocs partiellement lisibles',
    'expected'    => [
        'note' => 'Devrait quand même extraire quelque chose si lisible, posologie_brute jamais vide',
    ],
    'blocks' => <<<BLOCKS
[1|text] Dr R. Auteur-Dubon Généraliste
[2|text] Mme D  -  le  3/6/26
[3|text] Rp/
[4|text] Metformine  850  mg
[5|text] 1 cp  matin  + soir  x  3  mois
[6|text] Amlodipine  5mg
[7|text] 1 cp  le  matin
[8|text] Atorvastatine  20mg
[9|text] 1 cp  le  soir  HS
BLOCKS,
];

// ── CAS 4 : Contradiction interne (Paroxétine 28j simple + dégressif détaillé)
$cases['contradiction_interne'] = [
    'description' => 'Contradiction : dose simple 28j + phases détaillées sur même item → phases doivent primer',
    'expected'    => [
        'nb_items'   => 1,
        'has_phases' => [true],  // les phases priment, morning/noon/... de l'item = null
    ],
    'blocks' => <<<BLOCKS
[1|text] Dr. Martin, date : 20/05/2026
[2|text] Paroxétine 20 mg
[3|text] 2 cp / 28 jours
[4|text] Posologie détaillée : 2 cp 7j puis 1 cp 15j puis arrêt
[5|text] Dr. Martin
BLOCKS,
];

// ─── Exécution ────────────────────────────────────────────────────────────────

echo "\n=== GOLDEN SET OLLAMA — qwen2.5:1.5b-instruct ===\n";
echo "URL : {$ollamaUrl}\n";
echo str_repeat('─', 60) . "\n\n";

$allPassed = true;

foreach ($cases as $caseName => $case) {
    echo "▶ {$caseName}\n";
    echo "  {$case['description']}\n";
    echo "  " . str_repeat('·', 50) . "\n";

    $t0 = microtime(true);
    try {
        $result = callOllama($ollamaUrl, $model, buildPrompt($case['blocks']), $schema);
        $elapsed = round((microtime(true) - $t0) * 1000);
    } catch (RuntimeException $e) {
        echo "  ✗ ERREUR : {$e->getMessage()}\n\n";
        $allPassed = false;
        continue;
    }

    $items = $result['items'] ?? [];
    echo "  Prescripteur : " . ($result['prescriber_name'] ?? 'null') . "\n";
    echo "  Date         : " . ($result['prescribed_at']   ?? 'null') . "\n";
    echo "  Nb items     : " . count($items) . "\n";
    echo "  Temps        : {$elapsed} ms\n\n";

    foreach ($items as $i => $item) {
        $phases = $item['phases'] ?? [];
        echo "  Item " . ($i+1) . " : " . ($item['medication_name'] ?? '?') . "\n";
        echo "    dosage        : " . ($item['dosage'] ?? 'null') . "\n";
        echo "    intake_type   : " . ($item['intake_type'] ?? '?') . "\n";
        if (!empty($item['condition'])) {
            echo "    condition     : " . $item['condition'] . "\n";
        }
        if (!empty($item['max_per_day'])) {
            echo "    max_per_day   : " . $item['max_per_day'] . "\n";
        }
        $morning = $item['morning'] ?? 'null';
        $noon    = $item['noon']    ?? 'null';
        $evening = $item['evening'] ?? 'null';
        $bedtime = $item['bedtime'] ?? 'null';
        echo "    doses M/Mi/S/C: {$morning} / {$noon} / {$evening} / {$bedtime}\n";
        echo "    duration_days : " . ($item['duration_days'] ?? 'null') . "\n";
        echo "    qsp_days      : " . ($item['qsp_days']      ?? 'null') . "\n";
        echo "    posologie_brute: [" . substr($item['posologie_brute'] ?? '', 0, 70) . "]\n";
        echo "    phases        : " . count($phases) . " palier(s)\n";
        foreach ($phases as $pi => $phase) {
            $pm = $phase['morning'] ?? '-';
            $pn = $phase['noon']    ?? '-';
            $pe = $phase['evening'] ?? '-';
            $pb = $phase['bedtime'] ?? '-';
            echo "      Palier " . ($pi+1) . " : {$phase['duration_days']}j  M={$pm} Mi={$pn} S={$pe} C={$pb}\n";
        }
    }

    // Vérifications
    $exp = $case['expected'] ?? [];
    $checks = [];

    if (isset($exp['nb_items'])) {
        $ok = count($items) === $exp['nb_items'];
        $checks[] = ($ok ? '✓' : '✗') . " nb_items=" . count($items) . " (attendu {$exp['nb_items']})";
        if (!$ok) $allPassed = false;
    }

    if (isset($exp['types'])) {
        foreach ($exp['types'] as $idx => $expectedType) {
            $actualType = $items[$idx]['intake_type'] ?? '?';
            $ok = $actualType === $expectedType;
            $checks[] = ($ok ? '✓' : '✗') . " item[{$idx}].intake_type={$actualType} (attendu {$expectedType})";
            if (!$ok) $allPassed = false;
        }
    }

    if (isset($exp['has_phases'])) {
        foreach ($exp['has_phases'] as $idx => $shouldHavePhases) {
            $hasPhases = !empty($items[$idx]['phases'] ?? []);
            $ok = $hasPhases === $shouldHavePhases;
            $checks[] = ($ok ? '✓' : '✗') . " item[{$idx}].phases=" . ($hasPhases ? 'oui' : 'non') . " (attendu " . ($shouldHavePhases ? 'oui' : 'non') . ")";
            if (!$ok) $allPassed = false;
        }
    }

    // posologie_brute toujours remplie
    foreach ($items as $idx => $item) {
        $ok = !empty($item['posologie_brute']);
        $checks[] = ($ok ? '✓' : '✗') . " item[{$idx}].posologie_brute non vide";
        if (!$ok) $allPassed = false;
    }

    if (!empty($checks)) {
        echo "\n  VÉRIFICATIONS :\n";
        foreach ($checks as $c) {
            echo "    {$c}\n";
        }
    }

    echo "\n" . str_repeat('─', 60) . "\n\n";
}

echo $allPassed
    ? "✓ GOLDEN SET : TOUS LES CAS VALIDÉS\n"
    : "✗ GOLDEN SET : DES CAS ÉCHOUENT — voir ci-dessus\n";
