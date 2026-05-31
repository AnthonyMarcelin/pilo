<?php

namespace Database\Seeders;

use App\Models\MedicationReference;
use Illuminate\Database\Seeder;

class MedicationReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.

        // CIS 68416845 — LEVOTHYROX 100 µg (originator Levothyroxine sodique)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '68416845'],
            [
                'cip13'         => null,
                'name'          => 'LEVOTHYROX 100 µg, comprimé sécable',
                'units_per_box' => 30,
                'indication'    => "le traitement des hypothyroïdies, le traitement des circonstances, associées ou non à une hypothyroïdie, où l'on désire freiner la TSH.",
            ]
        );

        // CIS 63458349 — AMLOR 5 mg (originator Amlodipine)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '63458349'],
            [
                'cip13'         => null,
                'name'          => 'AMLOR 5 mg, gélule',
                'units_per_box' => 30,
                'indication'    => "Le service médical rendu par AMLOR reste important dans les indications de l'AMM.",
            ]
        );

        // CIS 60290800 — VALIUM 10 mg (originator Diazépam)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '60290800'],
            [
                'cip13'         => null,
                'name'          => 'VALIUM 10 mg, comprimé sécable',
                'units_per_box' => 40,
                'indication'    => "Le service médical rendu de VALIUM par voie orale reste important dans les indications : Traitement symptomatique des manifestations anxieuses sévères et/ou invalidantes, Prévention et traitement du delirium tremens et des autres manifestations du sevrage alcoolique.",
            ]
        );

        // CIS 60841335 — ZYPREXA 10 mg (originator Olanzapine)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '60841335'],
            [
                'cip13'         => null,
                'name'          => 'ZYPREXA 10 mg, comprimé enrobé',
                'units_per_box' => 28,
                'indication'    => "Le service médical rendu de ZYPREXA et ZYPREXA VELOTAB reste important dans le traitement de la schizophrénie, la prise en charge des épisodes maniaques modérés à sévères, dans la prévention des récidives bipolaires chez les patients ayant déjà répondu au traitement par l'olanzapine lors d'un épisode maniaque.",
            ]
        );

        // CIS 60756917 — CONCERTA LP 18 mg (originator Méthylphénidate)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '60756917'],
            [
                'cip13'         => null,
                'name'          => 'CONCERTA LP 18 mg, comprimé à libération prolongée',
                'units_per_box' => 30,
                'indication'    => "prise en charge thérapeutique globale du TDAH chez l'enfant de 6 ans et plus, lorsqu'une prise en charge psychologique, éducative et sociale seule s'avère insuffisante.",
            ]
        );

        // CIS 62678755 — NEURONTIN 100 mg (originator Gabapentine)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '62678755'],
            [
                'cip13'         => null,
                'name'          => 'NEURONTIN 100 mg, gélule',
                'units_per_box' => 100,
                'indication'    => "Le service médical rendu par NEURONTIN reste important dans les indications : Épilepsie et douleurs neuropathiques périphériques.",
            ]
        );

        // CIS 60160707 — DEROXAT 20 mg (originator Paroxétine)
        // Phase 6 pilo:import-bdpm fera un updateOrCreate identique — ces données seront remplacées par l'import BDPM complet.
        MedicationReference::updateOrCreate(
            ['cis' => '60160707'],
            [
                'cip13'         => null,
                'name'          => 'DEROXAT 20 mg, comprimé pelliculé sécable',
                'units_per_box' => 30,
                'indication'    => "les épisodes dépressifs majeurs (c'est-à-dire caractérisés), les troubles obsessionnels compulsifs, la prévention des attaques de paniques avec ou sans agoraphobie, le trouble de l'anxiété généralisée, l'état de stress post-traumatique.",
            ]
        );
    }
}
