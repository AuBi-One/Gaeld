<?php

namespace App\Domains\ImpactAccounting\Policies;

use App\Domains\ImpactAccounting\Models\CapitalObservation;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/** Authorization policy for observation history. */
class CapitalObservationPolicy extends BasePolicy
{
    public function update(User $user, CapitalObservation $observation): bool
    {
        return $this->belongsToOrganization($user, $observation)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }
}
