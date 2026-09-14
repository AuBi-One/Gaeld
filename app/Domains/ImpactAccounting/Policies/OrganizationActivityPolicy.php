<?php

namespace App\Domains\ImpactAccounting\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/** Authorization policy for the phase-one organization workshop. */
class OrganizationActivityPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ReportingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }
}
