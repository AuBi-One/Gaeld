<?php

namespace Plugins\ExpenseClaims\Listeners;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Enums\Role;
use App\Domains\Users\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Plugins\ExpenseClaims\Models\Claim;

/**
 * On login, tells a user who can approve claims (role with expenses.approve
 * in their current organisation) how many claims await approval, as an info
 * toast (flash message).
 */
final class ApprovalNotice
{
    public function handle(Login $event): void
    {
        try {
            $this->notify($event);
        } catch (\Throwable $e) {
            report($e); // never break a login because of the notice
        }
    }

    private function notify(Login $event): void
    {
        $user = $event->user;
        // Remember-me logins happen in the middle of a request: no toast there.
        if (! $user instanceof User || ! request()->hasSession() || Auth::guard($event->guard)->viaRemember()) {
            return;
        }

        $organization = $user->resolveCurrentOrganization();
        $pivot = $organization?->relationLoaded('pivot') ? $organization->getRelation('pivot') : null;
        $role = Role::tryFrom((string) ($pivot?->getAttribute('role') ?? ''));
        if ($organization === null || $role === null || ! in_array(Permission::ExpensesApprove, $role->permissions(), true)) {
            return;
        }

        $count = Claim::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', Claim::STATUS_DRAFT)
            ->count();
        if ($count > 0) {
            request()->session()->flash('info', trans_choice('expense-claims::ec.login_notice', $count, ['count' => $count]));
        }
    }
}
