<?php

namespace Plugins\ExpenseClaims\Http\Controllers;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\ClaimLine;
use Plugins\ExpenseClaims\Models\DebtRecord;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Models\Setting;
use Plugins\ExpenseClaims\Models\VehicleRate;
use Plugins\ExpenseClaims\Services\Distances;
use Plugins\ExpenseClaims\Services\Rates;

class SettingsController extends PluginController
{
    public function __construct(private Distances $distances, private Rates $rates) {}

    public function index(): Response
    {
        $this->authorizeWrite();
        $this->rates->ensureDefaults($this->orgId());

        return $this->page('ExpenseClaims/Settings', [
            'settings' => Setting::forOrganization($this->orgId())->only(array_keys(Setting::DEFAULTS)),
            'rates' => VehicleRate::query()->orderBy('vehicle_type')->orderByDesc('valid_from')->get(),
            'places' => Place::query()->ordered()->get(),
            'people' => Person::query()->with(['employee:id,first_name,last_name', 'user:id,name'])->orderBy('name')->get(),
            'members' => Organization::query()->findOrFail($this->orgId())->users()->orderBy('name')->get(['users.id', 'users.name']),
            'employees' => Employee::query()->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'contacts' => Contact::query()->orderBy('name')->limit(500)->get(['id', 'name', 'address', 'postal_code', 'city', 'country']),
            'organization' => Organization::query()->find($this->orgId(), ['name', 'address', 'postal_code', 'city', 'country']),
            'routing' => ['provider' => config('expense-claims.routing.provider'), 'configured' => $this->distances->isConfigured()],
        ]);
    }

    public function updateAccounts(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $rules = array_map(fn () => ['required', 'string', 'regex:/^\d{3,8}$/'], Setting::DEFAULTS);
        Setting::forOrganization($this->orgId())->update($request->validate($rules));

        return back()->with('success', __('expense-claims::ec.saved'));
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate([
            'vehicle_type' => ['required', 'string', 'max:20'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'rate_per_km' => ['required', 'numeric', 'gt:0', 'max:10'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        VehicleRate::query()->create($data + ['organization_id' => $this->orgId()]);

        return back()->with('success', __('expense-claims::ec.saved'));
    }

    public function destroyRate(VehicleRate $rate): RedirectResponse
    {
        $this->authorizeWrite();
        $rate->delete();

        return back()->with('success', __('expense-claims::ec.deleted'));
    }

    public function storePlace(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $this->placeData($request);
        $place = Place::query()->create(array_diff_key($data, ['person_id' => true]) + ['organization_id' => $this->orgId()]);
        $this->linkHome($place, $data['person_id'] ?? null);

        return back()->with('success', __('expense-claims::ec.saved'));
    }

    public function updatePlace(Request $request, Place $place): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $this->placeData($request);
        $place->fill(array_diff_key($data, ['person_id' => true]));
        if ($place->isDirty(['lat', 'lon'])) {
            $this->distances->forget($place);
        }
        $place->save();
        $this->linkHome($place, $data['person_id'] ?? null);

        return back()->with('success', __('expense-claims::ec.saved'));
    }

    public function destroyPlace(Place $place): RedirectResponse
    {
        $this->authorizeWrite();
        $used = ClaimLine::query()->where('from_place_id', $place->id)->orWhere('to_place_id', $place->id)->exists();
        if ($used) {
            throw new \DomainException(__('expense-claims::ec.place_in_use'));
        }
        $place->delete();

        return back()->with('success', __('expense-claims::ec.deleted'));
    }

    public function searchAddress(Request $request): JsonResponse
    {
        $this->authorizeWrite();
        $query = $request->validate(['q' => ['required', 'string', 'min:3', 'max:200']])['q'];

        return response()->json(['results' => $this->distances->search($query)]);
    }

    public function storePerson(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        Person::query()->create($this->personData($request) + ['organization_id' => $this->orgId()]);

        return back()->with('success', __('expense-claims::ec.saved'));
    }

    public function updatePerson(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeWrite();
        $person->update($this->personData($request, $person));

        return back()->with('success', __('expense-claims::ec.saved'));
    }

    public function destroyPerson(Person $person): RedirectResponse
    {
        $this->authorizeWrite();
        $used = Claim::query()->where('person_id', $person->id)->exists() || DebtRecord::query()->where('person_id', $person->id)->exists();
        if ($used) {
            throw new \DomainException(__('expense-claims::ec.person_in_use'));
        }
        $person->delete();

        return back()->with('success', __('expense-claims::ec.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function placeData(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(Place::KINDS)],
            'label' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $this->orgId())],
            'person_id' => ['nullable', 'uuid', 'prohibited_unless:kind,home', Rule::exists('ec_people', 'id')->where('organization_id', $this->orgId())],
        ]);
    }

    /** A home place chosen for a person becomes that person's home. */
    private function linkHome(Place $place, ?string $personId): void
    {
        if ($personId !== null) {
            Person::query()->whereKey($personId)->update(['home_place_id' => $place->id]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function personData(Request $request, ?Person $person = null): array
    {
        $data = $request->validate([
            'user_id' => [
                'nullable', 'integer', 'required_without:employee_id',
                Rule::exists('organization_users', 'user_id')->where('organization_id', $this->orgId()),
                Rule::unique('ec_people', 'user_id')->where('organization_id', $this->orgId())->ignore($person?->id),
            ],
            'employee_id' => [
                'nullable', 'uuid', 'required_without:user_id',
                Rule::exists('employees', 'id')->where('organization_id', $this->orgId()),
                Rule::unique('ec_people', 'employee_id')->where('organization_id', $this->orgId())->ignore($person?->id),
            ],
            'is_owner' => ['boolean'],
            'home_place_id' => ['nullable', 'uuid', Rule::exists('ec_places', 'id')->where('organization_id', $this->orgId())],
        ]);

        // The name comes from the employee record, else from the member's account.
        $employee = isset($data['employee_id']) ? Employee::query()->whereKey($data['employee_id'])->first() : null;
        $data['name'] = $employee !== null
            ? $employee->fullName()
            : (string) User::query()->whereKey($data['user_id'])->value('name');

        return $data;
    }
}
