<?php

namespace Tests\Feature\Organizations;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Audited models record their organisation in the activity log, so the
 * organisation's audit log lists their changes and nothing of other organisations.
 */
class AuditableOrganizationTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_activity_of_an_audited_model_carries_the_organization(): void
    {
        $account = $this->createAccount($this->organization, '1020', 'Bank');
        $account->update(['name' => 'Main bank']);
        $account->delete();

        $activities = Activity::query()
            ->where('subject_type', Account::class)
            ->where('subject_id', $account->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(['created', 'updated', 'deleted'], $activities->pluck('event')->all());
        foreach ($activities as $activity) {
            $this->assertSame($this->organization->id, $activity->properties['organization_id']);
        }
    }

    public function test_the_audit_log_lists_the_organizations_changes_only(): void
    {
        $mine = $this->createAccount($this->organization, '1020', 'Bank');

        $other = Organization::factory()->create();
        app(CurrentOrganization::class)->set($other);
        $theirs = $this->createAccount($other, '1020', 'Other bank');
        app(CurrentOrganization::class)->set($this->organization);
        $this->assertSame($other->id, Activity::where('subject_type', Account::class)->where('subject_id', $theirs->id)->firstOrFail()->properties['organization_id']);

        $this->actAsOrg()->get('/settings/activity-log')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Organizations/ActivityLog')
                ->where('activities.data', function ($rows) use ($mine, $theirs): bool {
                    $subjects = collect($rows)->where('subject_type', Account::class)->pluck('subject_id')->map(fn ($id) => (string) $id);

                    return $subjects->contains((string) $mine->id) && ! $subjects->contains((string) $theirs->id);
                })
                ->where('subjectTypes', fn ($types) => collect($types)->pluck('value')->contains(Account::class)));
    }

    private function createAccount(Organization $organization, string $code, string $name): Account
    {
        return Account::create([
            'organization_id' => $organization->id,
            'code' => $code,
            'name' => $name,
            'type' => AccountType::Asset->value,
        ]);
    }
}
