<?php

namespace App\Domains\ImpactAccounting\Policies;

use App\Domains\ImpactAccounting\Models\PreservationAction;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/** Authorization policy for preservation action history. */
class PreservationActionPolicy extends BasePolicy
{
    public function update(User $user, PreservationAction $action): bool
    {
        return $this->belongsToOrganization($user, $action)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }
}
