<?php

namespace Plugins\ExpenseClaims\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log of the money transitions. The Auditable trait on the models only
 * sees Eloquent events; the transitions below are query-builder updates, so
 * they are logged here explicitly (same activity log, same causer).
 */
final class Audit
{
    /**
     * @param  iterable<Model>  $subjects
     * @param  array<string, mixed>  $properties
     */
    public static function log(iterable $subjects, string $event, array $properties = []): void
    {
        foreach ($subjects as $subject) {
            activity()
                ->performedOn($subject)
                ->event($event)
                ->withProperties($properties + ['organization_id' => $subject->getAttribute('organization_id')])
                ->log(class_basename($subject)." {$event}");
        }
    }
}
