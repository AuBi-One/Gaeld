<?php

namespace Plugins\Offers\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferTemplate;

class TemplateController extends PluginController
{
    public function index(): Response
    {
        $this->authorizeView();
        $used = Offer::query()->whereNotNull('template_id')->groupBy('template_id')
            ->selectRaw('template_id, COUNT(*) AS n')->pluck('n', 'template_id');

        return $this->page('Offers/Templates', [
            'templates' => OfferTemplate::query()->orderBy('name')->get()
                ->map(fn (OfferTemplate $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'title' => $t->title,
                    'validity_days' => $t->validity_days,
                    'lines' => count($t->lines ?? []),
                    'is_default' => $t->is_default,
                    'used' => (int) ($used[$t->id] ?? 0),
                ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorizeWrite();

        return $this->page('Offers/TemplateForm', ['template' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $this->save($this->validated($request), new OfferTemplate(['organization_id' => $this->orgId()]));

        return redirect('/offer-templates')->with('success', __('offers::of.template_saved'));
    }

    public function edit(OfferTemplate $template): Response
    {
        $this->authorizeWrite();

        return $this->page('Offers/TemplateForm', ['template' => $template->only(['id', 'name', 'title', 'intro', 'closing', 'validity_days', 'lines', 'is_default'])]);
    }

    public function update(Request $request, OfferTemplate $template): RedirectResponse
    {
        $this->authorizeWrite();
        $this->save($this->validated($request), $template);

        return redirect('/offer-templates')->with('success', __('offers::of.template_saved'));
    }

    public function destroy(OfferTemplate $template): RedirectResponse
    {
        $this->authorizeWrite();
        $template->delete();

        return redirect('/offer-templates')->with('success', __('offers::of.template_deleted'));
    }

    /** @param  array<string, mixed>  $data */
    private function save(array $data, OfferTemplate $template): void
    {
        DB::transaction(function () use ($data, $template): void {
            if (! empty($data['is_default'])) {
                OfferTemplate::query()->when($template->exists, fn ($q) => $q->whereKeyNot($template->id))->update(['is_default' => false]);
            }
            $template->fill([
                'name' => $data['name'],
                'title' => $data['title'] ?? null,
                'intro' => $data['intro'] ?? null,
                'closing' => $data['closing'] ?? null,
                'validity_days' => (int) $data['validity_days'],
                'is_default' => (bool) ($data['is_default'] ?? false),
                'lines' => array_map(fn (array $l): array => [
                    'type' => $l['type'],
                    'label' => $l['label'] ?? null,
                    'description' => $l['description'],
                    'quantity' => $l['type'] === 'item' ? (string) $l['quantity'] : null,
                    'unit' => $l['type'] === 'item' ? ($l['unit'] ?? null) : null,
                    'unit_price' => $l['type'] === 'item' ? (string) $l['unit_price'] : null,
                ], array_values($data['lines'])),
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'closing' => ['nullable', 'string', 'max:20000'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'is_default' => ['boolean'],
            ...$this->lineRules(0),
        ]);
    }
}
