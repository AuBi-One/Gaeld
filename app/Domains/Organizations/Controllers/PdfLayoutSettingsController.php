<?php

namespace App\Domains\Organizations\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfLayouts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings: which registered PDF layout the organisation uses per document
 * (empty = core's standard layout).
 */
class PdfLayoutSettingsController extends Controller
{
    public function update(Request $request, CurrentOrganization $currentOrg, PdfLayouts $layouts): RedirectResponse
    {
        $organization = $currentOrg->get();
        $this->authorize('update', $organization);

        $documents = $layouts->documents();
        $rules = ['pdf_layouts' => ['present', 'array:'.implode(',', $documents)]];
        foreach ($documents as $document) {
            $rules["pdf_layouts.{$document}"] = ['nullable', 'string', Rule::in($layouts->keys($document))];
        }
        $validated = $request->validate($rules);

        // Only documents sent are changed; an empty value returns to the standard layout.
        $choices = $organization->pdf_layouts ?? [];
        foreach ($validated['pdf_layouts'] as $document => $key) {
            if ($key === null || $key === '') {
                unset($choices[$document]);
            } else {
                $choices[$document] = $key;
            }
        }
        $organization->update(['pdf_layouts' => $choices === [] ? null : $choices]);

        return redirect()->route('settings')
            ->with('success', __('app.pdf_layouts_updated'));
    }
}
