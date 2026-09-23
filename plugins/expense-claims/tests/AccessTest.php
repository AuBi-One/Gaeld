<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Users\Models\User;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Services\Claims;

/**
 * Screens and access (docs/DESIGN-expense-claims.md §8.2, §8.3).
 */
class AccessTest extends ExpenseClaimsTestCase
{
    private User $staff;

    private Person $me;

    private Person $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($this->staff->id, ['role' => 'employee']);
        $this->assignOrganizationRole($this->staff, $this->organization, 'employee');
        $home = Place::create(['organization_id' => $this->org->id, 'kind' => 'home', 'label' => 'Home me']);
        $otherHome = Place::create(['organization_id' => $this->org->id, 'kind' => 'home', 'label' => 'Home other']);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Office']);
        $this->me = Person::create(['organization_id' => $this->org->id, 'name' => 'Staff', 'user_id' => $this->staff->id, 'home_place_id' => $home->id]);
        $this->other = Person::create(['organization_id' => $this->org->id, 'name' => 'Other', 'employee_id' => $this->employee->id, 'home_place_id' => $otherHome->id]);
    }

    private function asStaff(): static
    {
        return $this->actingAs($this->staff)->withSession(['current_organization_id' => $this->organization->id]);
    }

    private function draft(Person $person): Claim
    {
        return app(Claims::class)->saveDraft($this->org->id, [
            'person_id' => $person->id, 'date' => '2026-03-10', 'title' => 'Repas', 'lines' => [['type' => 'meal', 'amount' => '12.00']],
        ]);
    }

    #[Test]
    public function an_employee_sees_and_creates_only_their_own_claims(): void
    {
        $mine = $this->draft($this->me);
        $this->draft($this->other);

        $this->asStaff()->get('/expense-claims')->assertOk()
            ->assertInertia(fn ($page) => $page->component('ExpenseClaims/Index', false)
                ->where('claims.total', 1)
                ->where('claims.data.0.id', $mine->id)
                ->where('balances.draft.total', '12.00')
                ->where('canManage', false));

        $this->asStaff()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canChoosePerson', false)
                ->where('people', fn ($people) => collect($people)->pluck('id')->all() === [$this->me->id])
                ->where('places', fn ($places) => collect($places)->pluck('label')->sort()->values()->all() === ['Home me', 'Office']));

        // The person is always their own, whatever is posted; saving returns to the list.
        $this->asStaff()->post('/expense-claims', [
            'person_id' => $this->other->id, 'date' => '2026-03-11', 'title' => 'Train', 'lines' => [['type' => 'transport', 'amount' => '8.40']],
        ])->assertRedirect('/expense-claims')->assertSessionHasNoErrors();
        $this->assertSame($this->me->id, Claim::where('title', 'Train')->value('person_id'));
    }

    #[Test]
    public function an_employee_cannot_approve_see_others_or_open_the_balances(): void
    {
        $mine = $this->draft($this->me);
        $theirs = $this->draft($this->other);

        $this->asStaff()->post("/expense-claims/{$mine->id}/approve")->assertForbidden();
        $this->asStaff()->post('/expense-balances/approve', ['ids' => [$mine->id]])->assertForbidden();
        $this->asStaff()->get("/expense-claims/{$theirs->id}")->assertForbidden();
        $this->asStaff()->get("/expense-claims/{$theirs->id}/edit")->assertForbidden();
        $this->asStaff()->get('/expense-balances')->assertForbidden();
        $this->asStaff()->get("/expense-claims/{$mine->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('canEdit', true)->where('canManage', false));

        app(Claims::class)->approve($mine);
        $this->asStaff()->get("/expense-claims/{$mine->id}/edit")->assertRedirect("/expense-claims/{$mine->id}");
        $this->asStaff()->get("/expense-claims/{$mine->id}")->assertInertia(fn ($page) => $page->where('canAttach', false));
    }

    #[Test]
    public function the_viewer_role_cannot_see_the_balances_or_other_claims(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');
        $claim = $this->draft($this->other);
        $as = fn () => $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id]);

        $as()->get('/expense-balances')->assertForbidden();
        $as()->get("/expense-claims/{$claim->id}")->assertForbidden();
        $as()->get("/expense-claims/{$claim->id}/attachments/0")->assertForbidden();
        $this->assertNotContains('expense_balances', array_column(
            array_filter(app(PluginNavigation::class)->toArray(), fn (array $i): bool => $viewer->hasPermissionTo((string) $i['permission'])),
            'key',
        ));
    }

    #[Test]
    public function a_manager_approves_pays_and_passes_to_debt_from_the_balances(): void
    {
        $a = $this->draft($this->me);
        $b = $this->draft($this->other);

        $this->actAsOrg()->post('/expense-balances/approve', ['ids' => [$a->id, $b->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([Claim::STATUS_APPROVED], Claim::pluck('status')->unique()->values()->all());
        $this->assertSame([$this->user->id], Claim::pluck('approved_by')->unique()->values()->all());

        $this->actAsOrg()->post('/expense-balances/pay', ['ids' => [$a->id], 'date' => '2026-04-01', 'account_code' => '9999'])->assertSessionHasErrors('account_code');
        $this->actAsOrg()->post('/expense-balances/pay', ['ids' => [$a->id], 'date' => '2026-04-01', 'account_code' => '1020'])->assertSessionHasNoErrors();
        $this->assertSame(Claim::STATUS_SETTLED, $a->fresh()->status);

        $this->actAsOrg()->post('/expense-balances/debt', ['ids' => [$b->id], 'date' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->assertSame(Claim::STATUS_DEBT, $b->fresh()->status);

        $this->actAsOrg()->get('/expense-balances?status=draft')->assertOk()
            ->assertInertia(fn ($page) => $page->where('claims', []));
    }

    #[Test]
    public function managers_get_a_login_notice_when_claims_await_approval(): void
    {
        $this->draft($this->me);
        $this->draft($this->other);
        $this->app['request']->setLaravelSession($this->app['session.store']);

        Event::dispatch(new Login('web', $this->staff, false));
        $this->assertNull(session('info'));

        Event::dispatch(new Login('web', $this->user, false));
        $this->assertSame('2 expense claims await approval (Expense balances).', session('info'));
    }
}
