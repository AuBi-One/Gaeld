<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

abstract class PluginController extends Controller
{
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
        abort_unless($this->allows(Permission::ExpensesApprove), 403);
    }

    protected function allows(Permission $permission): bool
    {
        return (bool) request()->user()?->hasPermissionTo($permission);
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
            'canManage' => $this->allows(Permission::ExpensesApprove),
        ]);
    }
}
