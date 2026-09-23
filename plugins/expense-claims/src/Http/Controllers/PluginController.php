<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Setting;

/**
 * Access (docs/DESIGN-expense-claims.md §8.2): everyone who can enter
 * expenses (`expenses.create`) works on their own claims; `expenses.view`
 * sees everyone's; `expenses.approve` ("manager") does every booking action.
 */
abstract class PluginController extends Controller
{
    private ?string $ownPersonId = null;

    private bool $ownPersonResolved = false;

    protected function orgId(): string
    {
        return app(CurrentOrganization::class)->id();
    }

    protected function authorizeView(): void
    {
        abort_unless($this->allows(Permission::ExpensesView), 403);
    }

    protected function authorizeWrite(): void
    {
        abort_unless($this->canManage(), 403);
    }

    protected function authorizeCreate(): void
    {
        abort_unless($this->allows(Permission::ExpensesCreate), 403);
    }

    protected function canManage(): bool
    {
        return $this->allows(Permission::ExpensesApprove);
    }

    protected function allows(Permission $permission): bool
    {
        return (bool) request()->user()?->hasPermissionTo($permission);
    }

    protected function isOwn(Claim $claim): bool
    {
        return $this->ownPersonId() !== null && $claim->person_id === $this->ownPersonId();
    }

    /** The claim's person, or anyone with `expenses.view`, may see a claim. */
    protected function authorizeSee(Claim $claim): void
    {
        abort_unless($this->isOwn($claim) || $this->allows(Permission::ExpensesView), 403);
    }

    /**
     * The person record of the logged-in user: linked to their account,
     * through their employee record, or an employee with their e-mail address.
     */
    protected function ownPersonId(): ?string
    {
        if ($this->ownPersonResolved) {
            return $this->ownPersonId;
        }
        $this->ownPersonResolved = true;
        $user = request()->user();
        if ($user === null) {
            return null;
        }

        $linked = Person::query()->where('user_id', $user->id)->value('id');
        if ($linked !== null) {
            return $this->ownPersonId = (string) $linked;
        }

        // Fallbacks only among people not linked to another account, and only when unambiguous.
        foreach ([
            fn ($q) => $q->where('user_id', $user->id),
            fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)]),
        ] as $employeeMatch) {
            $ids = Person::query()->whereNull('user_id')->whereHas('employee', $employeeMatch)->limit(2)->pluck('id');
            if ($ids->count() === 1) {
                return $this->ownPersonId = (string) $ids->first();
            }
        }

        return null;
    }

    /**
     * Bank and cash accounts a payment can come from: active asset accounts
     * 10xx (liquid assets in the Swiss chart) and the bank setting.
     *
     * @return list<array{code: string, name: string}>
     */
    protected function paymentAccounts(): array
    {
        $orgId = $this->orgId();
        $default = Setting::forOrganization($orgId)->bank_account_code;
        $accounts = array_values(Account::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('type', AccountType::Asset->value)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', $default))
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (Account $a): array => ['code' => (string) $a->code, 'name' => (string) $a->name])
            ->all());
        if (! in_array($default, array_column($accounts, 'code'), true)) {
            $accounts[] = ['code' => $default, 'name' => $default]; // created on first use
        }

        return $accounts;
    }

    /**
     * Render a plugin page; the plugin's strings are added to the shared
     * translations with an `ec_` prefix so pages can use t('ec_…').
     *
     * @param  array<string, mixed>  $props
     */
    protected function page(string $component, array $props = []): Response
    {
        $plugin = collect((array) trans('expense-claims::ec'))
            ->mapWithKeys(fn ($value, $key): array => ['ec_'.$key => $value])
            ->all();

        return Inertia::render($component, $props + [
            'translations' => array_merge((array) trans('app'), $plugin),
            'canManage' => $this->canManage(),
            'canCreate' => $this->allows(Permission::ExpensesCreate),
            'canViewAll' => $this->allows(Permission::ExpensesView),
        ]);
    }
}
