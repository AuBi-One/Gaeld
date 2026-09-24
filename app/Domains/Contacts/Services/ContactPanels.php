<?php

namespace App\Domains\Contacts\Services;

use App\Domains\Contacts\DTOs\ContactPanel;
use App\Domains\Contacts\Models\Contact;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

/**
 * Sections other features (e.g. plugins) add to the contact page, below the
 * contact's invoices and expenses. Nothing is registered in core.
 *
 * A provider receives the contact and returns a panel, or null to show
 * nothing (e.g. when the current user may not see those records: the
 * provider checks permissions itself). Registering a key again replaces the
 * earlier provider. A provider that fails is reported and skipped, so one
 * broken feature cannot break the contact page.
 */
#[Singleton]
final class ContactPanels
{
    /** @var array<string, Closure(Contact): ?ContactPanel> */
    private array $providers = [];

    /** @param  callable(Contact): ?ContactPanel  $provider  A closure or an invokable object */
    public function register(string $key, callable $provider): self
    {
        $this->providers[$key] = $provider(...);

        return $this;
    }

    /**
     * @return list<array<string, mixed>> The panels for this contact, in registration order
     */
    public function for(Contact $contact): array
    {
        $panels = [];
        foreach ($this->providers as $key => $provider) {
            try {
                $panel = $provider($contact);
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($panel !== null) {
                $panels[] = ['key' => $key] + $panel->toArray();
            }
        }

        return $panels;
    }
}
