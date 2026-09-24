<?php

namespace App\Support\Traits;

use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Adds organisation-scoped audit logging to a model.
 *
 * Uses Spatie Activity Log under the hood, automatically recording:
 *  - created / updated / deleted events
 *  - changed attributes (old → new)
 *  - the authenticated user (causer)
 *  - the organization_id via properties (models that have the attribute)
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => class_basename($this)." {$event}");
    }

    /**
     * Called by the activity log (v5) on the subject before the activity is saved.
     * Models without an organization_id attribute (lines, entries owned through a
     * parent) are not tagged; the parent's activity carries the organisation.
     */
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        // Eloquent attributes are not PHP properties: read the attribute, not property_exists().
        $organizationId = $this->getAttribute('organization_id');

        if ($organizationId && $activity instanceof ActivityModel) {
            $activity->properties = $activity->properties->merge([
                'organization_id' => $organizationId,
            ]);
        }
    }
}
