<?php

use App\Services\Ocr\OcrException;
use App\Services\Ocr\OllamaClient;

// ─── parseJsonDefensively ─────────────────────────────────────────────────────

it('parseJsonDefensively → parse un JSON propre', function () {
    $client = new OllamaClient('http://ollama:11434');
    $result = $client->parseJsonDefensively('{"items":[]}');
    expect($result)->toBeArray()->and($result['items'])->toBeArray();
});

it('parseJsonDefensively → strip les fences ```json...```', function () {
    $client = new OllamaClient('http://ollama:11434');
    $raw    = "```json\n{\"items\":[]}\n```";
    expect($client->parseJsonDefensively($raw))->toHaveKey('items');
});

it('parseJsonDefensively → strip les fences ``` sans json', function () {
    $client = new OllamaClient('http://ollama:11434');
    $raw    = "```\n{\"items\":[]}\n```";
    expect($client->parseJsonDefensively($raw))->toHaveKey('items');
});

it('parseJsonDefensively → lève OcrException sur JSON invalide', function () {
    $client = new OllamaClient('http://ollama:11434');
    expect(fn () => $client->parseJsonDefensively('not json at all'))->toThrow(OcrException::class);
});

it('parseJsonDefensively → lève OcrException sur chaîne vide', function () {
    $client = new OllamaClient('http://ollama:11434');
    expect(fn () => $client->parseJsonDefensively(''))->toThrow(OcrException::class);
});

it('parseJsonDefensively → lève OcrException sur JSON partiel', function () {
    $client = new OllamaClient('http://ollama:11434');
    expect(fn () => $client->parseJsonDefensively('{"items": ['))->toThrow(OcrException::class);
});

// ─── prescriptionSchema ───────────────────────────────────────────────────────

it('prescriptionSchema → contient les champs requis du contrat SPEC §8', function () {
    $schema = OllamaClient::prescriptionSchema();
    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toContain('items')
        ->and($schema['properties']['items']['items']['properties'])
        ->toHaveKey('medication_name')
        ->toHaveKey('intake_type')
        ->toHaveKey('posologie_brute')
        ->toHaveKey('phases');
});

it('prescriptionSchema → intake_type enum contient les 3 types', function () {
    $schema = OllamaClient::prescriptionSchema();
    $intakeEnum = $schema['properties']['items']['items']['properties']['intake_type']['enum'];
    expect($intakeEnum)->toContain('fixe')->toContain('si_besoin')->toContain('autre');
});
