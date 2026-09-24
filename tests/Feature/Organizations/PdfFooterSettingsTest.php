<?php

namespace Tests\Feature\Organizations;

use App\Domains\Users\Models\User;
use App\Support\Pdf\PdfFooter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class PdfFooterSettingsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_the_footer_text_can_be_set_and_cleared(): void
    {
        $this->assertSame('© '.now()->year.' Gäld', PdfFooter::text($this->org));

        $this->actAsOrg()->put(route('settings.pdf-footer'), ['pdf_footer_text' => 'Example SA · :year'])->assertRedirect(route('settings'));
        $this->assertSame('Example SA · '.now()->year, PdfFooter::text($this->org->fresh()));

        $this->actAsOrg()->put(route('settings.pdf-footer'), ['pdf_footer_text' => ''])->assertRedirect(route('settings'));
        $this->assertNull($this->org->fresh()->pdf_footer_text);
        $this->assertSame('© '.now()->year.' Gäld', PdfFooter::text($this->org->fresh()));

        $this->actAsOrg()->put(route('settings.pdf-footer'), ['pdf_footer_text' => str_repeat('x', 256)])->assertSessionHasErrors('pdf_footer_text');
    }

    public function test_members_who_may_not_edit_the_organisation_cannot_change_it(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->org, 'viewer');

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id])
            ->put(route('settings.pdf-footer'), ['pdf_footer_text' => 'x'])->assertForbidden();
        $this->assertNull($this->org->fresh()->pdf_footer_text);
    }
}
