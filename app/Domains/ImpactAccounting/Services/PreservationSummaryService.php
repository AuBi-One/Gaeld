<?php

namespace App\Domains\ImpactAccounting\Services;

use App\Domains\ImpactAccounting\Models\OrganizationActivity;
use App\Domains\ImpactAccounting\Models\PreservationCapital;

class PreservationSummaryService
{
    /**
     * Summarize preservation data without calculating a composite score.
     *
     * @return array{organization_id: string, capital_count: int, capitals: array<int, array<string, mixed>>}
     */
    public function summary(string $organizationId): array
    {
        $capitals = PreservationCapital::query()
            ->where('organization_id', $organizationId)
            ->with(['observations', 'impacts', 'preservationActions'])
            ->orderBy('name')
            ->get();
        $activities = OrganizationActivity::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        return [
            'organization_id' => $organizationId,
            'activity_count' => $activities->count(),
            'activities' => $activities->map(fn (OrganizationActivity $activity): array => [
                'id' => $activity->id,
                'name' => $activity->name,
                'purpose' => $activity->purpose,
                'inputs' => $activity->inputs,
                'outputs' => $activity->outputs,
                'source_reference' => $activity->source_reference,
                'status' => $activity->status,
            ])->values()->all(),
            'capital_count' => $capitals->count(),
            'capitals' => $capitals->map(function (PreservationCapital $capital): array {
                $latestObservation = $capital->observations
                    ->sortByDesc('observed_on')
                    ->first();

                return [
                    'id' => $capital->id,
                    'name' => $capital->name,
                    'status' => $capital->status,
                    'latest_observation' => $latestObservation === null ? null : [
                        'observed_on' => $latestObservation->observed_on->toDateString(),
                        'value' => $latestObservation->value,
                        'unit' => $latestObservation->unit,
                        'confidence' => $latestObservation->confidence,
                    ],
                    'observations' => $capital->observations
                        ->sortByDesc('observed_on')
                        ->map(fn ($observation): array => [
                            'id' => $observation->id,
                            'observed_on' => $observation->observed_on->toDateString(),
                            'value' => $observation->value,
                            'unit' => $observation->unit,
                            'source_reference' => $observation->source_reference,
                            'confidence' => $observation->confidence,
                            'notes' => $observation->notes,
                        ])->values()->all(),
                    'impact_count' => $capital->impacts->count(),
                    'impacts' => $capital->impacts
                        ->sortByDesc('occurred_on')
                        ->map(fn ($impact): array => [
                            'id' => $impact->id,
                            'activity_name' => $impact->activity_name,
                            'impact_type' => $impact->impact_type,
                            'impact_direction' => $impact->impact_direction,
                            'occurred_on' => $impact->occurred_on->toDateString(),
                            'value' => $impact->value,
                            'unit' => $impact->unit,
                            'source_reference' => $impact->source_reference,
                            'notes' => $impact->notes,
                        ])->values()->all(),
                    'open_action_count' => $capital->preservationActions
                        ->whereNotIn('status', ['completed', 'cancelled'])
                        ->count(),
                    'action_counts' => $capital->preservationActions
                        ->groupBy(fn ($action): string => $action->action_type->value)
                        ->map(fn ($actions): int => $actions->count())
                        ->all(),
                    'actions' => $capital->preservationActions
                        ->sortBy('due_on')
                        ->map(fn ($action): array => [
                            'id' => $action->id,
                            'title' => $action->title,
                            'description' => $action->description,
                            'action_type' => $action->action_type->value,
                            'status' => $action->status,
                            'due_on' => $action->due_on?->toDateString(),
                            'source_reference' => $action->source_reference,
                        ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
