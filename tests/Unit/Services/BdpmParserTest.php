<?php

use App\Services\Bdpm\BdpmParser;

$FIXTURES = __DIR__ . '/../../fixtures/bdpm';

// ─── extractUnitsPerBox ───────────────────────────────────────────────────────

dataset('units_per_box', [
    'boite de 30'              => ['PARACETAMOL 500 mg - Boîte de 30 comprimés', 30],
    'boite de 14'              => ['PAROXETINE 20 mg - Boîte de 14', 14],
    'plaquette de 28'          => ['DOLIPRANE 1000 mg - plaquette de 28 comprimés', 28],
    'plaquette(s) de 8'        => ['DOLI 500 mg - plaquette(s) de 8 comprimés', 8],
    'boite de 3 gelules'       => ['FLUCONAZOLE 150 mg - Boîte de 3', 3],
    'boite de 30 comprimes'    => ['LEVOTHYROX 100 µg - Boîte de 30 comprimés', 30],
    'flacon de 100 mL'         => ['SIROP AMOX - Boîte de 1 flacon de 100 mL', 1],
    'pattern nombre + forme'   => ['PARACETAMOL 500mg - Boîte de 16 comprimés effervescents', 16],
    'libelle sans boite'       => ['SUBSTANCE SANS CONDITIONNEMENT EXPLICITE', null],
]);

it('extractUnitsPerBox → extrait correctement', function (string $label, ?int $expected) {
    expect((new BdpmParser())->extractUnitsPerBox($label))->toBe($expected);
})->with('units_per_box');

// ─── extractName ─────────────────────────────────────────────────────────────

it('extractName → coupe avant le tiret de conditionnement', function () {
    $parser = new BdpmParser();

    expect($parser->extractName('PAROXETINE BIOGARAN 20 mg, comprimé pelliculé - Boîte de 30'))
        ->toBe('PAROXETINE BIOGARAN 20 mg, comprimé pelliculé');

    expect($parser->extractName('LEVOTHYROX 100 microgrammes - Boîte de 30 comprimés'))
        ->toBe('LEVOTHYROX 100 microgrammes');

    expect($parser->extractName('PAS DE TIRET'))
        ->toBe('PAS DE TIRET');
});

// ─── parseCisCip ─────────────────────────────────────────────────────────────

it('parseCisCip → lit et indexe par CIS', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisCip("{$FIXTURES}/CIS_CIP_bdpm.txt");

    expect($result)->toHaveKey('64013600')
        ->and($result['64013600']['units_per_box'])->toBe(30)
        ->and($result['64013600']['cip13'])->toBe('3400936228089')
        ->and($result['64013600']['name'])->toContain('LEVOTHYROX');
});

it('parseCisCip → plusieurs CIP pour un CIS → garde le dernier', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisCip("{$FIXTURES}/CIS_CIP_bdpm.txt");

    // Paroxétine a 2 lignes (boîte de 30 puis boîte de 14) → on garde la dernière
    expect($result)->toHaveKey('60025516')
        ->and($result['60025516']['units_per_box'])->toBe(14);
});

// ─── parseCisHasSMR ──────────────────────────────────────────────────────────

it('parseCisHasSMR → retourne le libellé SMR le plus récent non creux', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisHasSMR("{$FIXTURES}/CIS_HAS_SMR_bdpm.txt");

    // Paroxétine originator : date 2023 a un libellé valide → doit être choisi
    expect($result)->toHaveKey('61234567')
        ->and($result['61234567'])->toContain('épisodes dépressifs');
});

it('parseCisHasSMR → ignore les libellés creux "dans l\'indication de l\'AMM"', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisHasSMR("{$FIXTURES}/CIS_HAS_SMR_bdpm.txt");

    // La ligne 2020 est creuse, mais la ligne 2023 est valide → on garde 2023
    expect($result['61234567'])->not->toContain("dans l'indication de l'AMM");
});

it('parseCisHasSMR → retourne null si aucun libellé valide', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisHasSMR("{$FIXTURES}/CIS_HAS_SMR_bdpm.txt");

    // Fluconazole → libelle vide → pas de clé
    expect($result)->not->toHaveKey('60006756');
});

// ─── parseCisGener ───────────────────────────────────────────────────────────

it('parseCisGener → mappe générique → CIS originator', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisGener("{$FIXTURES}/CIS_GENER_bdpm.txt");

    expect($result)->toHaveKey('60025516')
        ->and($result['60025516'])->toBe('61234567');   // générique → originator

    expect($result)->toHaveKey('60025517')
        ->and($result['60025517'])->toBe('61234567');
});

it('parseCisGener → l\'originator n\'est pas dans la map', function () use ($FIXTURES) {
    $result = (new BdpmParser())->parseCisGener("{$FIXTURES}/CIS_GENER_bdpm.txt");

    // L'originator lui-même ne doit pas pointer vers lui-même
    expect($result)->not->toHaveKey('61234567');
});

// ─── Intégration : indication héritée par le générique ───────────────────────

it('integration → la paroxétine générique hérite l\'indication de l\'originator via GENER', function () use ($FIXTURES) {
    $parser = new BdpmParser();

    $cip   = $parser->parseCisCip("{$FIXTURES}/CIS_CIP_bdpm.txt");
    $smr   = $parser->parseCisHasSMR("{$FIXTURES}/CIS_HAS_SMR_bdpm.txt");
    $gener = $parser->parseCisGener("{$FIXTURES}/CIS_GENER_bdpm.txt");

    // Le générique 60025516 n'a pas d'entrée SMR propre
    expect($smr)->not->toHaveKey('60025516');

    // Via le lien GENER, on remonte à l'originator 61234567 qui a une indication
    $originatorCis = $gener['60025516'];  // '61234567'
    $indication    = $smr[$originatorCis] ?? null;

    expect($indication)->not->toBeNull()
        ->and($indication)->toContain('épisodes dépressifs');
});
