<?php
/**
 * Comparaison qwen2.5:1.5b vs 3b sur le golden set Pilo.
 *
 * Sections :
 *   A — Golden set standard (4 cas)
 *   B — Robustesse OCR dégradé (typos, ordre mélangé, médoc sur 2 lignes)
 *   C — Critère anti-invention : l'info est absente → null ou invention ?
 *
 * Usage : php tests/golden_set_compare.php
 * (depuis le conteneur app ou avec l'URL Ollama accessible)
 */

$OLLAMA  = 'http://ollama:11434';
$MODELS  = ['qwen2.5:1.5b-instruct', 'qwen2.5:3b-instruct'];

// ─── Schema ──────────────────────────────────────────────────────────────────

$SCHEMA = [
    'type' => 'object', 'required' => ['items'],
    'properties' => [
        'prescriber_name' => ['type' => ['string','null']],
        'prescribed_at'   => ['type' => ['string','null']],
        'items' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'required' => ['medication_name','intake_type','posologie_brute'],
            'properties' => [
                'medication_name' => ['type' => 'string'],
                'dosage'          => ['type' => ['string','null']],
                'intake_type'     => ['type' => 'string','enum' => ['fixe','si_besoin','autre']],
                'morning'         => ['type' => ['number','null']],
                'noon'            => ['type' => ['number','null']],
                'evening'         => ['type' => ['number','null']],
                'bedtime'         => ['type' => ['number','null']],
                'condition'       => ['type' => ['string','null']],
                'max_per_day'     => ['type' => ['number','null']],
                'duration_days'   => ['type' => ['integer','null']],
                'qsp_days'        => ['type' => ['integer','null']],
                'posologie_brute' => ['type' => 'string'],
                'phases' => ['type' => 'array', 'items' => [
                    'type' => 'object','required' => ['duration_days'],
                    'properties' => [
                        'duration_days' => ['type' => 'integer'],
                        'morning'  => ['type' => ['number','null']],
                        'noon'     => ['type' => ['number','null']],
                        'evening'  => ['type' => ['number','null']],
                        'bedtime'  => ['type' => ['number','null']],
                    ],
                ]],
            ],
        ]],
    ],
];

// ─── Prompt (few-shot, identique à OllamaClient) ────────────────────────────

function prompt(string $blocks): string {
    return <<<P
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
$blocks
P;
}

// ─── Appel Ollama ─────────────────────────────────────────────────────────────

function ollama(string $url, string $model, string $prompt, array $schema): array {
    $ch = curl_init("{$url}/api/generate");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model'=>$model,'prompt'=>$prompt,'format'=>$schema,'stream'=>false]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 240,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException("HTTP {$code}");
    $resp = json_decode($raw, true);
    $json = $resp['response'] ?? '';
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $json, $m)) $json = trim($m[1]);
    return json_decode($json, true) ?? [];
}

// ─── Helpers affichage ─────────────────────────────────────────────────────────

function item_line(array $item): string {
    $phases = $item['phases'] ?? [];
    $ph = count($phases) === 0 ? '—' : implode(' ', array_map(
        fn($p) => "[{$p['duration_days']}j M={$p['morning']} Mi={$p['noon']} S={$p['evening']}]",
        $phases
    ));
    $cond = $item['condition'] ?? 'null';
    $pb   = substr($item['posologie_brute'] ?? '', 0, 55);
    $dur  = $item['duration_days'] ?? 'null';
    return sprintf(
        "  %-30s  %-10s  M=%-3s Mi=%-3s S=%-3s C=%-3s  dur=%-4s  cond=%-20s  phases=%s\n  posologie_brute=[%s]",
        ($item['medication_name'] ?? '?') . ' ' . ($item['dosage'] ?? ''),
        $item['intake_type'] ?? '?',
        $item['morning'] ?? 'null',
        $item['noon']    ?? 'null',
        $item['evening'] ?? 'null',
        $item['bedtime'] ?? 'null',
        $dur,
        $cond,
        $ph,
        $pb,
    );
}

function run_case(string $url, array $models, array $schema, string $name, string $blocks, array $checks = []): void {
    echo "\n╔═══ {$name} " . str_repeat('═', max(0, 58 - strlen($name))) . "╗\n";
    $results = [];
    foreach ($models as $model) {
        $short = str_replace('qwen2.5:', '', $model);
        $t0 = microtime(true);
        try {
            $r = ollama($url, $model, prompt($blocks), $schema);
            $ms = round((microtime(true)-$t0)*1000);
            $results[$short] = ['data' => $r, 'ms' => $ms, 'err' => null];
        } catch (Throwable $e) {
            $results[$short] = ['data' => [], 'ms' => 0, 'err' => $e->getMessage()];
        }
    }

    // Affichage côte à côte
    foreach ($results as $short => $res) {
        echo "\n  ── {$short} ({$res['ms']} ms) ";
        if ($res['err']) { echo "ERREUR: {$res['err']}\n"; continue; }
        $d = $res['data'];
        echo "(prescripteur=" . ($d['prescriber_name']??'null') . "  date=" . ($d['prescribed_at']??'null') . "  items=" . count($d['items']??[]) . ")\n";
        foreach (($d['items'] ?? []) as $i => $item) {
            echo "\n  [" . ($i+1) . "] " . item_line($item) . "\n";
        }
    }

    // Vérifications croisées
    if (!empty($checks)) {
        echo "\n  CHECKS :\n";
        foreach ($checks as $label => $fn) {
            foreach ($results as $short => $res) {
                if ($res['err']) continue;
                $ok = $fn($res['data']);
                echo "  " . ($ok ? '✓' : '✗') . " [{$short}] {$label}\n";
            }
        }
    }
    echo "╚" . str_repeat('═', 62) . "╝\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION A — Golden set standard
// ═══════════════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat('█', 64) . "\n";
echo "  SECTION A — GOLDEN SET STANDARD\n";
echo str_repeat('█', 64) . "\n";

run_case($OLLAMA, $MODELS, $SCHEMA,
    'A1 — Ordonnance imprimée (4 items)',
    <<<B
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
B,
    [
        'nb_items=4'      => fn($d) => count($d['items']??[]) === 4,
        'Levothyrox fixe' => fn($d) => ($d['items'][0]['intake_type']??'') === 'fixe',
        'Gabapentine fixe'=> fn($d) => ($d['items'][1]['intake_type']??'') === 'fixe',
        'Diazépam si_besoin' => fn($d) => ($d['items'][3]['intake_type']??'') === 'si_besoin',
        'Levo morning=1'  => fn($d) => (float)($d['items'][0]['morning']??0) === 1.0,
        'Gaba noon≥1'     => fn($d) => (float)($d['items'][1]['noon']??0) >= 1.0,
        'Gaba evening≥2'  => fn($d) => (float)($d['items'][1]['evening']??0) >= 2.0,
        'Prednisone phases>0' => fn($d) => count($d['items'][2]['phases']??[]) > 0,
        'posologie_brute non vide (tous)' => fn($d) => !array_filter($d['items']??[], fn($i) => empty($i['posologie_brute'])),
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'A2 — Paroxétine dégressive 3 paliers',
    <<<B
[1|text] Dr. Lefebvre Sophie, Psychiatre — 8 avenue Gambetta — 75020 Paris
[2|text] Date : 29/05/2026 — Patient : Mme D. — ORDONNANCE SÉCURISÉE
[3|text] Paroxétine 20 mg, comprimé pelliculé
[4|text] 2 comprimés le matin pendant 28 jours (schéma dégressif ci-dessous)
[5|text] Schéma posologique :
[6|text] Phase 1 : 2 comprimés/matin × 7 jours
[7|text] Phase 2 : 1 comprimé/matin × 15 jours
[8|text] Phase 3 : arrêt
[9|text] Durée totale : 22 jours
[10|text] À administrer sous surveillance médicale.
B,
    [
        'nb_items=1'      => fn($d) => count($d['items']??[]) === 1,
        'intake=fixe'     => fn($d) => ($d['items'][0]['intake_type']??'') === 'fixe',
        'phases≥2'        => fn($d) => count($d['items'][0]['phases']??[]) >= 2,
        'phase1 dur=7'    => fn($d) => (int)($d['items'][0]['phases'][0]['duration_days']??0) === 7,
        'phase1 morning=2'=> fn($d) => (float)($d['items'][0]['phases'][0]['morning']??0) === 2.0,
        'phase2 morning=1'=> fn($d) => (float)($d['items'][0]['phases'][1]['morning']??0) === 1.0,
        'item morning=null (phases priment)' => fn($d) => ($d['items'][0]['morning']??'X') === null,
    ]
);

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION B — Robustesse OCR dégradé
// ═══════════════════════════════════════════════════════════════════════════════

echo "\n\n" . str_repeat('█', 64) . "\n";
echo "  SECTION B — ROBUSTESSE OCR DÉGRADÉ\n";
echo str_repeat('█', 64) . "\n";

run_case($OLLAMA, $MODELS, $SCHEMA,
    'B1 — Typos plausibles OCR (caractères confondus)',
    <<<B
[1|text] Dr. Martln Paul, Medecin G6neraliste
[2|text] Date : 0l/06/2026  (Patient Mme D.)
[3|text] Levothyrox lO0 µg comprime secable
[4|text] l comprime Ie matin a jeun — duree 365j
[5|text] Gabapentlne l00 mg gelule
[6|text] l gel matln l gel midl 2 gel solr — 90 jours
B,
    [
        'Levothyrox détecté' => fn($d) => count(array_filter($d['items']??[], fn($i) => stripos($i['medication_name']??'','levothyrox') !== false)) > 0,
        'Gabapentine détecté' => fn($d) => count(array_filter($d['items']??[], fn($i) => stripos($i['medication_name']??'','gabapent') !== false)) > 0,
        'intake_type valides' => fn($d) => !array_filter($d['items']??[], fn($i) => !in_array($i['intake_type']??'', ['fixe','si_besoin','autre'])),
        'posologie_brute non vide' => fn($d) => !array_filter($d['items']??[], fn($i) => empty($i['posologie_brute'])),
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'B2 — Ordre de lecture mélangé (posologie avant le nom)',
    <<<B
[3|text] 1 comprimé le matin pendant 365 jours
[1|text] Dr. Martin — date 15/04/2026
[4|text] Gabapentine 100 mg
[2|text] Levothyrox 100 µg
[5|text] 2 gélules le soir, 1 gélule le matin — Durée 90j
[6|text] 1 comprimé le matin à jeun
B,
    [
        '≥2 items détectés'  => fn($d) => count($d['items']??[]) >= 2,
        'posologie_brute non vide' => fn($d) => !array_filter($d['items']??[], fn($i) => empty($i['posologie_brute'])),
        'intake_type valides' => fn($d) => !array_filter($d['items']??[], fn($i) => !in_array($i['intake_type']??'', ['fixe','si_besoin','autre'])),
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'B3 — Médicament coupé sur 2 lignes',
    <<<B
[1|text] Dr. Bernard, Généraliste — 10/05/2026
[2|text] Metformine chlorhydrate
[3|text] 850 mg, comprimé pelliculé
[4|text] 1 cp matin et soir aux repas, pendant 3 mois (90 jours)
[5|text] Amlodipine 5 mg
[6|text] 1 cp le matin — durée longue
B,
    [
        'Metformine détecté' => fn($d) => count(array_filter($d['items']??[], fn($i) => stripos($i['medication_name']??'','metformine') !== false)) > 0,
        'Amlodipine détecté' => fn($d) => count(array_filter($d['items']??[], fn($i) => stripos($i['medication_name']??'','amlodipine') !== false)) > 0,
        'posologie_brute non vide' => fn($d) => !array_filter($d['items']??[], fn($i) => empty($i['posologie_brute'])),
    ]
);

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION C — Critère ANTI-INVENTION (prioritaire)
// ═══════════════════════════════════════════════════════════════════════════════

echo "\n\n" . str_repeat('█', 64) . "\n";
echo "  SECTION C — ANTI-INVENTION (critère de choix prioritaire)\n";
echo "  Règle : info absente → null. Inventer est inacceptable.\n";
echo str_repeat('█', 64) . "\n";

run_case($OLLAMA, $MODELS, $SCHEMA,
    'C1 — Absence totale de posologie (juste le nom)',
    <<<B
[1|text] Dr. Test — 01/01/2026
[2|text] Aspirine 100 mg
B,
    [
        'morning=null'   => fn($d) => ($d['items'][0]['morning']??'X') === null,
        'condition=null' => fn($d) => ($d['items'][0]['condition']??'X') === null,
        'max_per_day=null' => fn($d) => ($d['items'][0]['max_per_day']??'X') === null,
        'duration_days=null' => fn($d) => ($d['items'][0]['duration_days']??'X') === null,
        'posologie_brute non vide' => fn($d) => !empty($d['items'][0]['posologie_brute']??''),
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'C2 — Ordonnance sans nom prescripteur ni date',
    <<<B
[1|text] Doliprane 1000 mg, comprimé
[2|text] 1 comprimé le soir au coucher si douleur
B,
    [
        'prescriber_name=null' => fn($d) => ($d['prescriber_name']??'X') === null,
        'prescribed_at=null'   => fn($d) => ($d['prescribed_at']??'X') === null,
        'intake=si_besoin'     => fn($d) => ($d['items'][0]['intake_type']??'') === 'si_besoin',
        'condition non null'   => fn($d) => ($d['items'][0]['condition']??null) !== null,
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'C3 — Médicament sans dosage explicite',
    <<<B
[1|text] Dr. Durand — 05/06/2026
[2|text] Metoprolol
[3|text] 1 comprimé le matin, durée indéterminée
B,
    [
        'dosage=null'        => fn($d) => ($d['items'][0]['dosage']??'X') === null,
        'duration_days=null' => fn($d) => ($d['items'][0]['duration_days']??'X') === null,
        'morning=1'          => fn($d) => (float)($d['items'][0]['morning']??0) === 1.0,
        'posologie_brute non vide' => fn($d) => !empty($d['items'][0]['posologie_brute']??''),
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'C4 — Blocs totalement vides (image noire simulée)',
    <<<B
[1|text]
[2|text]
B,
    [
        'items vide ou 0 item' => fn($d) => count($d['items']??[]) === 0,
    ]
);

run_case($OLLAMA, $MODELS, $SCHEMA,
    'C5 — Texte ambigu (condition non explicitée)',
    <<<B
[1|text] Dr. Noir — 20/06/2026
[2|text] Ibuprofène 400 mg
[3|text] 1 comprimé au besoin
B,
    [
        'intake=si_besoin'   => fn($d) => ($d['items'][0]['intake_type']??'') === 'si_besoin',
        'condition≠invention' => fn($d) => in_array($d['items'][0]['condition']??null, [null, '', 'au besoin', 'si besoin', 'selon besoin'], false),
        'max_per_day=null'   => fn($d) => ($d['items'][0]['max_per_day']??'X') === null,
    ]
);

// ─── Résumé ───────────────────────────────────────────────────────────────────

echo "\n\n" . str_repeat('▓', 64) . "\n";
echo "  FIN DU COMPARATIF — interprétation humaine requise\n";
echo "  Critère de sélection prioritaire : C (anti-invention)\n";
echo str_repeat('▓', 64) . "\n\n";
