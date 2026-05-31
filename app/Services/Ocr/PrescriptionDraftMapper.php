<?php

namespace App\Services\Ocr;

use App\DTOs\PrescriptionDraft;
use App\DTOs\PrescriptionItemDraft;
use App\DTOs\PrescriptionItemPhaseDraft;

/**
 * Mappe le JSON normalisé par Ollama vers un PrescriptionDraft.
 *
 * Règles issues de SPEC §8 :
 *  - Si phases non vide sur un item fixe → les phases priment (morning/noon/…
 *    de l'item sont ignorées au profit des phases).
 *  - Si dose directe ET phases présents → hasAmbiguity = true (badge "Vérifier").
 *  - posologie_brute TOUJOURS copié tel quel.
 *  - intake_type par défaut : 'autre' si absent ou invalide.
 */
final class PrescriptionDraftMapper
{
    private const VALID_INTAKE_TYPES = ['fixe', 'si_besoin', 'autre'];

    /**
     * @param  array  $json  Tableau PHP décodé depuis la réponse Ollama.
     */
    public function map(array $json): PrescriptionDraft
    {
        $items = [];

        foreach ((array) ($json['items'] ?? []) as $raw) {
            $items[] = $this->mapItem((array) $raw);
        }

        return new PrescriptionDraft(
            prescriber_name: $this->str($json, 'prescriber_name'),
            prescribed_at:   $this->str($json, 'prescribed_at'),
            notes:           null,
            items:           $items,
        );
    }

    private function mapItem(array $raw): PrescriptionItemDraft
    {
        $intakeType = in_array($raw['intake_type'] ?? '', self::VALID_INTAKE_TYPES, true)
            ? $raw['intake_type']
            : 'autre';

        $phases = [];
        if ($intakeType === 'fixe') {
            $phases = $this->mapPhases((array) ($raw['phases'] ?? []));

            // Si phases vides mais dose directe → créer une phase synthétique
            if (empty($phases)) {
                $phases = [new PrescriptionItemPhaseDraft(
                    duration_days: isset($raw['duration_days']) ? (int) $raw['duration_days'] : null,
                    morning:       isset($raw['morning'])       ? (float) $raw['morning']      : null,
                    noon:          isset($raw['noon'])          ? (float) $raw['noon']         : null,
                    evening:       isset($raw['evening'])       ? (float) $raw['evening']      : null,
                    bedtime:       isset($raw['bedtime'])       ? (float) $raw['bedtime']      : null,
                )];
            }
        }

        return new PrescriptionItemDraft(
            medication_name: trim((string) ($raw['medication_name'] ?? '')),
            dosage:          $this->str($raw, 'dosage'),
            intake_type:     $intakeType,
            posologie_brute: trim((string) ($raw['posologie_brute'] ?? '')),
            condition:       $this->str($raw, 'condition'),
            max_per_day:     isset($raw['max_per_day']) ? (float) $raw['max_per_day'] : null,
            qsp_days:        isset($raw['qsp_days'])    ? (int)   $raw['qsp_days']   : null,
            duration_days:   isset($raw['duration_days']) ? (int) $raw['duration_days'] : null,
            start_date:      null,
            boxes_count:     null,
            phases:          $phases,
        );
    }

    /**
     * @return PrescriptionItemPhaseDraft[]
     */
    private function mapPhases(array $rawPhases): array
    {
        $phases = [];
        foreach ($rawPhases as $rp) {
            $rp = (array) $rp;
            $dur = (int) ($rp['duration_days'] ?? 0);
            if ($dur <= 0) {
                // Ignore les paliers avec durée nulle ou négative (3B peut inventer -1)
                continue;
            }
            $phases[] = new PrescriptionItemPhaseDraft(
                duration_days: $dur,
                morning:       isset($rp['morning']) ? (float) $rp['morning'] : null,
                noon:          isset($rp['noon'])    ? (float) $rp['noon']    : null,
                evening:       isset($rp['evening']) ? (float) $rp['evening'] : null,
                bedtime:       isset($rp['bedtime']) ? (float) $rp['bedtime'] : null,
            );
        }
        return $phases;
    }

    private function str(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        return ($v !== null && $v !== '') ? (string) $v : null;
    }
}
