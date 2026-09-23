<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\DTOs\JournalEntryReference;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

class JournalEntryOwnerApiTest extends SecurityTestCase
{
    public function test_api_refuses_to_delete_an_owned_draft_but_posts_it(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        foreach ([['1020', 'Bank', AccountType::Asset], ['3000', 'Revenue', AccountType::Revenue]] as [$code, $name, $type]) {
            Account::create(['organization_id' => $this->orgA->id, 'code' => $code, 'name' => $name, 'type' => $type->value]);
        }
        $token = $this->createApiToken($this->ownerA, $this->orgA);

        $id = $this->withToken($token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-08-21',
            'reference' => 'OWNED-1',
            'description' => 'Owned',
            'status' => 'draft',
            'lines' => [
                ['account_code' => '1020', 'debit' => '100.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '100.00'],
            ],
        ])->assertCreated()->json('data.id');

        app(JournalEntryReferences::class)->register(fn (array $ids): array => in_array($id, $ids, true)
            ? [$id => new JournalEntryReference('Expense claim EC-0001')]
            : []);

        $this->withToken($token)->deleteJson("/api/v1/journal-entries/{$id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Created by Expense claim EC-0001. Manage it there.');
        $this->assertDatabaseHas('journal_entries', ['id' => $id]);

        $this->withToken($token)->withHeader('Idempotency-Key', 'owned-post')
            ->postJson("/api/v1/journal-entries/{$id}/post")
            ->assertOk();
        $this->assertDatabaseHas('journal_entries', ['id' => $id, 'is_posted' => true]);
    }
}
