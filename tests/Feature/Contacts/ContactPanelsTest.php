<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\DTOs\ContactPanel;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Services\ContactPanels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Sections registered by other features appear on the contact page, per contact.
 */
class ContactPanelsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    private function contact(string $name = 'Panel AG'): Contact
    {
        return Contact::create(['organization_id' => $this->organization->id, 'name' => $name]);
    }

    public function test_the_contact_page_has_no_panels_by_default(): void
    {
        $contact = $this->contact('Plain AG');

        $this->actAsOrg()->get("/contacts/{$contact->uuid}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('panels', []));
    }

    public function test_registered_panels_are_shown_for_the_contact_and_may_opt_out(): void
    {
        $contact = $this->contact();
        $other = $this->contact('Other AG');

        app(ContactPanels::class)
            ->register('demo', fn (Contact $c): ContactPanel => new ContactPanel(
                title: 'Demo records',
                columns: [
                    ['key' => 'number', 'label' => 'Number'],
                    ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                    ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'type' => 'money'],
                ],
                rows: [['cells' => ['number' => "D-{$c->id}", 'date' => '2026-03-10', 'total' => '10.00'], 'href' => "/demo/{$c->id}", 'currency' => 'CHF']],
                emptyText: 'None yet',
                action: ['label' => 'All', 'href' => "/demo?contact={$c->id}"],
            ))
            ->register('only-other', fn (Contact $c): ?ContactPanel => $c->is($other) ? new ContactPanel('Other only', [], [], 'Nothing here') : null);

        $this->actAsOrg()->get("/contacts/{$contact->uuid}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('panels', 1)
                ->where('panels.0.key', 'demo')
                ->where('panels.0.title', 'Demo records')
                ->where('panels.0.columns.0.align', 'left')
                ->where('panels.0.columns.2.align', 'right')
                ->where('panels.0.columns.2.type', 'money')
                ->where('panels.0.rows.0.cells.number', "D-{$contact->id}")
                ->where('panels.0.rows.0.href', "/demo/{$contact->id}")
                ->where('panels.0.rows.0.currency', 'CHF')
                ->where('panels.0.empty_text', 'None yet')
                ->where('panels.0.action.href', "/demo?contact={$contact->id}"));

        // empty panel: no rows, no action; order kept
        $this->actAsOrg()->get("/contacts/{$other->uuid}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('panels', 2)
                ->where('panels.1.key', 'only-other')
                ->where('panels.1.rows', [])
                ->where('panels.1.action', null)
                ->where('panels.1.empty_text', 'Nothing here'));
    }

    public function test_a_key_registered_again_replaces_the_earlier_provider(): void
    {
        $contact = $this->contact();
        app(ContactPanels::class)
            ->register('demo', fn (): ContactPanel => new ContactPanel('First', [], []))
            ->register('demo', fn (): ContactPanel => new ContactPanel('Second', [], []));

        $this->actAsOrg()->get("/contacts/{$contact->uuid}")
            ->assertInertia(fn ($page) => $page->has('panels', 1)->where('panels.0.title', 'Second'));
    }

    public function test_unsafe_links_are_dropped_and_a_failing_provider_is_skipped(): void
    {
        $contact = $this->contact();
        app(ContactPanels::class)
            ->register('broken', fn (): ContactPanel => throw new RuntimeException('boom'))
            ->register('links', fn (): ContactPanel => new ContactPanel(
                'Links',
                [['key' => 'name', 'label' => 'Name']],
                [
                    ['cells' => ['name' => 'script'], 'href' => 'javascript:alert(1)'],
                    ['cells' => ['name' => 'protocol-relative'], 'href' => '//evil.test/x'],
                    ['cells' => ['name' => 'backslash'], 'href' => '/\\evil.test/x', 'currency' => 'Fr.'],
                    ['cells' => ['name' => 'external'], 'href' => 'https://example.test/a'],
                    ['cells' => ['name' => 'relative'], 'href' => '/contacts'],
                ],
                action: ['label' => 'x', 'href' => 'data:text/html,x'],
            ));

        $this->actAsOrg()->get("/contacts/{$contact->uuid}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('panels', 1)
                ->where('panels.0.rows.0.href', null)
                ->where('panels.0.rows.1.href', null)
                ->where('panels.0.rows.2.href', null)
                ->where('panels.0.rows.2.currency', null)
                ->where('panels.0.rows.3.href', 'https://example.test/a')
                ->where('panels.0.rows.4.href', '/contacts')
                ->where('panels.0.action', null));
    }

    public function test_invalid_columns_are_refused(): void
    {
        foreach ([['key' => 'x', 'label' => 'X', 'type' => 'html'], ['label' => 'no key'], ['key' => 'x', 'align' => 'center', 'label' => 'X']] as $column) {
            try {
                new ContactPanel('Bad', [$column], []);
                $this->fail('accepted an invalid column');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
