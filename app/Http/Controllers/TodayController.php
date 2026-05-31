<?php

namespace App\Http\Controllers;

use App\Services\DailyRegimen;
use App\Services\Regimen\DailyRegimenResult;
use App\Services\Regimen\ScheduledEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TodayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $today   = now();
        $regimen = (new DailyRegimen($request->user()->id))->forDate($today);

        $moments = ['morning', 'noon', 'evening', 'bedtime'];
        $fixed   = [];

        foreach ($moments as $moment) {
            $fixed[$moment] = array_map(
                fn (ScheduledEntry $e) => $this->serializeEntry($e, $moment),
                $regimen->fixed[$moment] ?? [],
            );
        }

        return Inertia::render('Today', [
            'todayLabel' => $today->locale('fr')->isoFormat('dddd D MMMM YYYY'),
            'regimen'    => [
                'fixed'    => $fixed,
                'asNeeded' => array_map(fn ($e) => $this->serializeEntry($e), $regimen->asNeeded),
                'special'  => array_map(fn ($e) => $this->serializeEntry($e), $regimen->special),
            ],
            'alerts'     => $regimen->alerts,
        ]);
    }

    private function serializeEntry(ScheduledEntry $e, ?string $moment = null): array
    {
        return [
            'id'               => $e->prescriptionItemId,
            'name'             => $e->medicationName,
            'dosageLabel'      => $e->dosageLabel,
            'qty'              => $moment !== null ? $e->qtyForMoment($moment) : null,
            'posologieBrute'   => $e->posologieBrute,
            'isTerminated'     => $e->isTerminated,
            'hasTapering'      => $e->hasTapering(),
            'dayInPhase'       => $e->dayInPhase,
            'phaseDurationDays'=> $e->phaseDurationDays,
            'totalPhases'      => $e->totalPhases,
            'nextChangeNote'   => $e->nextChangeNote,
            'condition'        => $e->condition,
            'maxPerDay'        => $e->maxPerDay,
            'endDateLabel'     => $e->endDateLabel,
        ];
    }
}
