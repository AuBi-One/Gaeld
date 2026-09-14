<?php

namespace App\Domains\ImpactAccounting\Policies;

use App\Domains\ImpactAccounting\Models\PreservationCapital;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/** Authorization policy for reading preservation accounting data. */
class PreservationCapitalPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ReportingView);
    }

    public function view(User $user, PreservationCapital $capital): bool
    {
        return $this->belongsToOrganization($user, $capital)
            && $user->hasPermissionTo(Permission::ReportingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }

    public function createObservation(User $user, PreservationCapital $capital): bool
    {
        return $this->belongsToOrganization($user, $capital)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }

    public function createImpact(User $user, PreservationCapital $capital): bool
    {
        return $this->belongsToOrganization($user, $capital)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }

    public function createAction(User $user, PreservationCapital $capital): bool
    {
        return $this->belongsToOrganization($user, $capital)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }
}
