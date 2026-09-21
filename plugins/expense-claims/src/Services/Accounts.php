<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerQueryService;

/**
 * Resolves ledger accounts by code and creates the ones this plugin needs when
 * the organisation's chart does not have them yet (never renames an existing one).
 */
final class Accounts
{
    /** Names from the Swiss SME chart (Banana, French edition). */
    private const NAMES = [
        '6640' => 'Frais de déplacement',
        '2210' => 'Autres dettes envers les tiers',
        '2260' => 'Autres dettes envers personne concernée',
        '2261' => 'Autres dettes envers membres du conseil d\'administration',
        '2262' => 'Autres dettes envers membres de la direction',
        '2560' => 'Autres dettes à long terme envers personne concernée',
        '2561' => 'Autres dettes à long terme envers membres du conseil d\'administration',
        '2562' => 'Autres dettes à long terme envers membres de la direction',
        '5820' => 'Frais de voyages',
        '5821' => 'Frais de repas',
        '5822' => 'Frais de logement',
    ];

    public function __construct(private LedgerQueryService $ledgerQuery) {}

    public function ensure(string $organizationId, string $code): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        return $account ?? Account::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => self::NAMES[$code] ?? "Compte {$code}",
            'type' => $this->typeFor($code),
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    public function id(string $organizationId, string $code): string
    {
        return (string) $this->ensure($organizationId, $code)->id;
    }

    /**
     * A unique journal reference: the base, or base-2, base-3… when an
     * earlier entry (e.g. one since reversed) already uses it.
     */
    public function uniqueReference(string $organizationId, string $base): string
    {
        $reference = $base;
        for ($n = 2; $this->ledgerQuery->isDuplicateReferenceAny($organizationId, $reference); $n++) {
            $reference = "{$base}-{$n}";
        }

        return $reference;
    }

    private function typeFor(string $code): string
    {
        return match ($code[0] ?? '') {
            '1' => AccountType::Asset->value,
            '2' => AccountType::Liability->value,
            '3' => AccountType::Revenue->value,
            default => AccountType::Expense->value,
        };
    }
}
