<?php

namespace Plugins\Offers\Http\Controllers;

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
        abort_unless($this->allows(Permission::InvoicingView), 403);
    }

    protected function authorizeWrite(): void
    {
        abort_unless($this->allows(Permission::InvoicingCreate), 403);
    }

    protected function authorizeDelete(): void
    {
        abort_unless($this->allows(Permission::InvoicingCreate) && $this->allows(Permission::InvoicingDelete), 403);
    }

    protected function allows(Permission $permission): bool
    {
        return (bool) request()->user()?->hasPermissionTo($permission);
    }

    /**
     * Render a plugin page; the plugin's strings are added to the shared
     * translations with an `of_` prefix so pages can use t('of_…').
     *
     * @param  array<string, mixed>  $props
     */
    protected function page(string $component, array $props = []): Response
    {
        $plugin = collect((array) trans('offers::of'))
            ->mapWithKeys(fn ($value, $key): array => ['of_'.$key => $value])
            ->all();

        return Inertia::render($component, $props + [
            'translations' => array_merge((array) trans('app'), $plugin),
            'canManage' => $this->allows(Permission::InvoicingCreate),
            'canDelete' => $this->allows(Permission::InvoicingCreate) && $this->allows(Permission::InvoicingDelete),
        ]);
    }

    /**
     * Validation rules for offer or template lines.
     *
     * @return array<string, mixed>
     */
    protected function lineRules(int $min): array
    {
        // Same bounds as core invoice lines (quantity decimal(10,2), > 0); a rebate is a negative price.

        return [
            'lines' => [$min > 0 ? 'required' : 'present', 'array', "min:{$min}", 'max:200'],
            'lines.*.type' => ['required', 'in:item,text'],
            'lines.*.label' => ['nullable', 'string', 'max:20'],
            'lines.*.description' => ['required', 'string', 'max:5000'],
            'lines.*.quantity' => ['nullable', 'required_if:lines.*.type,item', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'lines.*.unit' => ['nullable', 'string', 'max:30'],
            'lines.*.unit_price' => ['nullable', 'required_if:lines.*.type,item', 'numeric', 'regex:/^-?\d{1,9}(\.\d{1,2})?$/'],
        ];
    }
}
