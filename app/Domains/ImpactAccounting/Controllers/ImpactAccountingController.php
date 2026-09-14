<?php

namespace App\Domains\ImpactAccounting\Controllers;

use App\Domains\ImpactAccounting\Models\CapitalImpact;
use App\Domains\ImpactAccounting\Models\CapitalObservation;
use App\Domains\ImpactAccounting\Models\OrganizationActivity;
use App\Domains\ImpactAccounting\Models\PreservationAction;
use App\Domains\ImpactAccounting\Models\PreservationCapital;
use App\Domains\ImpactAccounting\Requests\StoreCapitalImpactRequest;
use App\Domains\ImpactAccounting\Requests\StoreCapitalObservationRequest;
use App\Domains\ImpactAccounting\Requests\StoreOrganizationActivityRequest;
use App\Domains\ImpactAccounting\Requests\StorePreservationActionRequest;
use App\Domains\ImpactAccounting\Requests\StorePreservationCapitalRequest;
use App\Domains\ImpactAccounting\Requests\UpdateCapitalObservationRequest;
use App\Domains\ImpactAccounting\Requests\UpdatePreservationActionRequest;
use App\Domains\ImpactAccounting\Services\PreservationSummaryService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Support\FeatureFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ImpactAccountingController extends Controller
{
    public function index(
        CurrentOrganization $currentOrganization,
        PreservationSummaryService $summaryService,
    ): InertiaResponse {
        $organization = $currentOrganization->get();

        $this->ensureEnabled($organization);

        $this->authorize('viewAny', PreservationCapital::class);

        return Inertia::render('ImpactAccounting/Index', [
            'summary' => $summaryService->summary($organization->id),
        ]);
    }

    public function store(
        StorePreservationCapitalRequest $request,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $organization = $currentOrganization->get();
        $this->ensureEnabled($organization);
        $this->authorize('create', PreservationCapital::class);

        PreservationCapital::create([
            ...$request->validated(),
            'organization_id' => $organization->id,
        ]);

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_capital_created'));
    }

    public function storeActivity(
        StoreOrganizationActivityRequest $request,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $organization = $currentOrganization->get();
        $this->ensureEnabled($organization);
        $this->authorize('create', OrganizationActivity::class);

        OrganizationActivity::create([
            ...$request->validated(),
            'organization_id' => $organization->id,
        ]);

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_activity_created'));
    }

    public function storeObservation(
        StoreCapitalObservationRequest $request,
        PreservationCapital $capital,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $organization = $currentOrganization->get();
        $this->ensureEnabled($organization);
        $this->authorize('createObservation', $capital);

        CapitalObservation::create([
            ...$request->validated(),
            'organization_id' => $organization->id,
            'preservation_capital_id' => $capital->id,
        ]);

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_observation_created'));
    }

    public function storeImpact(
        StoreCapitalImpactRequest $request,
        PreservationCapital $capital,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $organization = $currentOrganization->get();
        $this->ensureEnabled($organization);
        $this->authorize('createImpact', $capital);

        CapitalImpact::create([
            ...$request->validated(),
            'organization_id' => $organization->id,
            'preservation_capital_id' => $capital->id,
        ]);

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_impact_created'));
    }

    public function storeAction(
        StorePreservationActionRequest $request,
        PreservationCapital $capital,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $organization = $currentOrganization->get();
        $this->ensureEnabled($organization);

        $this->authorize('createAction', $capital);

        PreservationAction::create([
            ...$request->validated(),
            'organization_id' => $organization->id,
            'preservation_capital_id' => $capital->id,
        ]);

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_action_created'));
    }

    public function updateObservation(
        UpdateCapitalObservationRequest $request,
        CapitalObservation $observation,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $this->ensureEnabled($currentOrganization->get());
        $this->authorize('update', $observation);
        $observation->update($request->validated());

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_observation_updated'));
    }

    public function updateAction(
        UpdatePreservationActionRequest $request,
        PreservationAction $action,
        CurrentOrganization $currentOrganization,
    ): RedirectResponse {
        $this->ensureEnabled($currentOrganization->get());
        $this->authorize('update', $action);
        $action->update($request->validated());

        return redirect()
            ->route('impact-accounting.index')
            ->with('success', __('app.impact_action_updated'));
    }

    private function ensureEnabled(Organization $organization): void
    {
        abort_unless(
            FeatureFlag::enabledForOrg('impact_accounting', $organization),
            Response::HTTP_FORBIDDEN,
            'Impact accounting is not enabled for this organization.',
        );
    }
}
