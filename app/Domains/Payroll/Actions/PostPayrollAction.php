<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Queries\DeductionRateSetQuery;
use App\Domains\Payroll\Services\NullReimbursementSource;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posts a salary slip to the accounting ledger (gross salary, deductions, net pay).
 */
class PostPayrollAction
{
    /**
     * Deduction-code prefix => fixed account, used when a code has no
     * explicit account mapping on its deduction rate line (none configured,
     * or the employee has no code / no set covers this period and the
     * built-in defaults were used, which carry no account at all). Keeps
     * pre-existing organisations posting exactly where they always did.
     */
    private const LEGACY_ACCOUNT_BY_PREFIX = [
        'avs_' => AccountCode::AVS_PAYABLE,
        'aanp_' => AccountCode::AVS_PAYABLE,
        'ac_' => AccountCode::AC_PAYABLE,
        'lpp_' => AccountCode::LPP_PAYABLE,
    ];

    /**
     * Keys {@see SwissDeductionService::calculateDeductions()} and the
     * calculator add to a slip's `deductions` that are not themselves a
     * per-line deduction amount, so the account-mapping loop below must
     * skip them.
     */
    private const NON_RATE_DEDUCTION_KEYS = [
        'total_employee', 'total_employer', 'net_salary', 'base_salary',
        'thirteenth_salary', 'unpaid_leave_days', 'unpaid_leave_amount',
        'reimbursement_amount', 'source_tax',
    ];

    public function __construct(
        private LedgerService $ledger,
        private LedgerQueryService $ledgerQuery,
        private SendSalarySlipEmailAction $sendEmail,
        private SourceTaxServiceInterface $sourceTax,
        private ReimbursementSourceInterface $reimbursements = new NullReimbursementSource,
    ) {}

    public function execute(SalarySlip $slip): SalarySlip
    {
        $this->ensureSourceTaxApplied($slip);

        $deductions = $slip->deductions;
        $orgId = $slip->organization_id;

        $employee = $slip->employee;
        $description = "Salary {$employee->fullName()} — {$slip->period_month}/{$slip->period_year}";

        // Resolve accounts
        $salaryAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::SALARIES);
        $socialChargesAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::SOCIAL_CHARGES_EMPLOYER);
        $bankAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::BANK_CASH);

        $sourceTaxAmount = Money::normalize((string) ($deductions['source_tax'] ?? $slip->source_tax_amount ?? '0.00'));

        $lines = [];

        // Debit: Gross salary
        $lines[] = new JournalLineData(
            accountId: (string) $salaryAccount->id,
            debit: $slip->gross_salary,
            credit: '0',
            description: "Gross salary: {$employee->fullName()}",
        );

        // Debit: Employer social charges
        $totalEmployer = $deductions['total_employer'] ?? '0';
        if (Money::isPositive($totalEmployer)) {
            $lines[] = new JournalLineData(
                accountId: (string) $socialChargesAccount->id,
                debit: $totalEmployer,
                credit: '0',
                description: "Employer social charges: {$employee->fullName()}",
            );
        }

        // Credit: Bank (net salary)
        $lines[] = new JournalLineData(
            accountId: (string) $bankAccount->id,
            debit: '0',
            credit: $slip->net_salary,
            description: "Net salary paid: {$employee->fullName()}",
        );

        // Itemised reimbursements are re-checked against their source (still
        // open, same amount) and debited, summed per account, to the account(s)
        // the source names (optional splits); the manual remainder keeps the
        // general expense account.
        $reimbursementAmount = (string) ($deductions['reimbursement_amount'] ?? '0.00');
        $items = $this->currentReimbursementItems($slip);
        $byAccount = [];
        foreach ($items as $item) {
            $reimbursementAmount = Money::subtract($reimbursementAmount, $item['amount']);
            foreach ($item['splits'] ?? [['account_code' => $item['account_code'], 'amount' => $item['amount']]] as $split) {
                $byAccount[$split['account_code']][] = ['amount' => $split['amount'], 'label' => $item['label']];
            }
        }
        foreach ($byAccount as $accountCode => $group) {
            $lines[] = new JournalLineData(
                accountId: (string) $this->ledgerQuery->resolveAccount($orgId, (string) $accountCode)->id,
                debit: array_reduce($group, fn (string $sum, array $item): string => Money::add($sum, $item['amount']), Money::zero()),
                credit: '0',
                description: mb_strimwidth("Expense reimbursement: {$employee->fullName()} — ".implode(', ', array_column($group, 'label')), 0, 255, '…'),
            );
        }
        if (Money::isPositive($reimbursementAmount)) {
            $reimbursementAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::GENERAL_EXPENSE);
            $lines[] = new JournalLineData(
                accountId: (string) $reimbursementAccount->id,
                debit: $reimbursementAmount,
                credit: '0',
                description: "Expense reimbursement: {$employee->fullName()}",
            );
        }

        // Credit: every deduction line, grouped by the account its rate is
        // mapped to in Payroll > Charges sociales (falling back to the fixed
        // AVS/AC/LPP accounts when a line has no mapping, so an organisation
        // that never opened that screen posts exactly as before). Grouping
        // by resolved account, not by code, keeps the entry balanced however
        // many distinct deduction codes or accounts are involved: every code
        // present in `deductions` lands in exactly one credit line here.
        foreach ($this->creditsByAccount($slip, $deductions) as $accountId => $credit) {
            $lines[] = new JournalLineData(
                accountId: (string) $accountId,
                debit: '0',
                credit: $credit['amount'],
                description: $credit['description'],
            );
        }

        if (Money::isPositive($sourceTaxAmount)) {
            $sourceTaxAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::WITHHOLDING_TAX_PAYABLE);
            $lines[] = new JournalLineData(
                accountId: (string) $sourceTaxAccount->id,
                debit: '0',
                credit: $sourceTaxAmount,
                description: 'Withholding tax payable',
            );
        }

        // Build a human-friendly reference: PAY-<INITIALS>-<YYYY>-<MM>.
        // Falls back to a short employee-id slug when initials are unavailable.
        $initials = collect((array) preg_split('/\s+/', trim((string) $employee->fullName())))
            ->filter()
            ->map(fn ($p) => strtoupper(mb_substr((string) $p, 0, 1)))
            ->take(3)
            ->implode('');
        $tag = $initials !== '' ? $initials : substr((string) $slip->employee_id, 0, 4);
        $monthPad = str_pad((string) $slip->period_month, 2, '0', STR_PAD_LEFT);

        $entry = new JournalEntryData(
            date: Carbon::create($slip->period_year, $slip->period_month)->endOfMonth()->toDateString(),
            reference: "PAY-{$tag}-{$slip->period_year}-{$monthPad}",
            description: $description,
            lines: $lines,
        );

        DB::transaction(function () use ($orgId, $entry, $slip, $items): void {
            $journalEntry = $this->ledger->postEntry($orgId, $entry);

            $slip->update([
                'journal_entry_id' => $journalEntry->id,
                'posted_at' => now(),
            ]);

            if ($items !== []) {
                $this->reimbursements->settle($slip, $items);
            }
        });

        $postedSlip = $slip->fresh();
        $this->sendEmail->execute($postedSlip);

        return $postedSlip;
    }

    /**
     * Every per-line deduction amount on the slip, grouped by the
     * chart-of-accounts entry it should credit.
     *
     * @param  array<string, mixed>  $deductions
     * @return array<int, array{amount: string, description: string}>
     */
    private function creditsByAccount(SalarySlip $slip, array $deductions): array
    {
        $employee = $slip->employee;
        $orgId = $slip->organization_id;

        $periodDate = Carbon::create($slip->period_year, $slip->period_month, 1)->toDateString();
        $rateSet = DeductionRateSetQuery::resolve($orgId, $employee->deduction_rate_code, $periodDate);

        /** @var array<string, int> $accountIdByCode */
        $accountIdByCode = [];
        /** @var array<string, string> $nameByCode */
        $nameByCode = [];
        if ($rateSet) {
            foreach ($rateSet->rates as $rate) {
                $nameByCode[$rate->code] = $rate->name;
                if ($rate->account_id !== null) {
                    $accountIdByCode[$rate->code] = $rate->account_id;
                }
            }
        }
        foreach (SwissDeductionService::defaults() as $default) {
            $nameByCode[$default['code']] ??= $default['name'];
        }

        $credits = [];
        foreach ($deductions as $code => $amount) {
            if (in_array($code, self::NON_RATE_DEDUCTION_KEYS, true)) {
                continue;
            }

            $amount = (string) $amount;
            if (! Money::isPositive($amount)) {
                continue;
            }

            $accountId = $accountIdByCode[$code] ?? $this->legacyAccountId($orgId, $code);
            if ($accountId === null) {
                throw new \DomainException(
                    "No chart-of-accounts entry is mapped to the deduction \"{$code}\" (\"{$slip->employee->fullName()}\", "
                    ."{$slip->period_month}/{$slip->period_year}). Set one for it in Payroll > Charges sociales before posting this slip.",
                );
            }

            $name = $nameByCode[$code] ?? $code;
            if (! isset($credits[$accountId])) {
                $credits[$accountId] = ['amount' => Money::zero(), 'names' => []];
            }
            $credits[$accountId]['amount'] = Money::add($credits[$accountId]['amount'], $amount);
            if (! in_array($name, $credits[$accountId]['names'], true)) {
                $credits[$accountId]['names'][] = $name;
            }
        }

        return array_map(
            fn (array $credit): array => [
                'amount' => $credit['amount'],
                'description' => 'Social charges: '.implode(', ', $credit['names']),
            ],
            $credits,
        );
    }

    /**
     * The fixed AVS/AC/LPP account a deduction code posted to before any
     * mapping existed, matched by its code prefix. Null for anything else
     * (APGM, allocations familiales, a custom charge, ...), which then needs
     * an explicit mapping.
     */
    private function legacyAccountId(string $organizationId, string $code): ?int
    {
        foreach (self::LEGACY_ACCOUNT_BY_PREFIX as $prefix => $accountCode) {
            if (str_starts_with($code, $prefix)) {
                return (int) $this->ledgerQuery->resolveAccount($organizationId, $accountCode)->id;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, date: string, label: string, amount: string, account_code: string, splits?: list<array{account_code: string, amount: string}>}>
     */
    private function currentReimbursementItems(SalarySlip $slip): array
    {
        $stored = $slip->adjustments['reimbursement_items'] ?? [];
        if ($stored === []) {
            return [];
        }

        $current = $this->reimbursements->resolve(
            (string) $slip->organization_id,
            (string) $slip->employee_id,
            array_map(fn (array $item): string => (string) $item['id'], $stored),
        );

        // Each item must still have the amount stored on the slip, and its splits must add up to it.
        $storedAmounts = array_column($stored, 'amount', 'id');
        foreach ($current as $item) {
            $splits = $item['splits'] ?? [['account_code' => $item['account_code'], 'amount' => $item['amount']]];
            $splitTotal = array_reduce($splits, fn (string $sum, array $split): string => Money::add($sum, $split['amount']), Money::zero());
            if (! isset($storedAmounts[$item['id']])
                || Money::compare((string) $storedAmounts[$item['id']], $item['amount']) !== 0
                || Money::compare($splitTotal, $item['amount']) !== 0) {
                throw new \DomainException('Reimbursement items changed since the salary slip was generated. Delete and regenerate the slip.');
            }
        }

        return $current;
    }

    private function ensureSourceTaxApplied(SalarySlip $slip): void
    {
        if ($slip->source_tax_base === null) {
            $this->sourceTax->applyToSlip($slip, $slip->employee);
        }

        if ($slip->source_tax_base === null) {
            return;
        }

        $deductions = $slip->deductions;
        if (array_key_exists('source_tax', $deductions)) {
            return;
        }

        $sourceTaxAmount = Money::normalize((string) ($slip->source_tax_amount ?? '0.00'));
        $deductions['source_tax'] = $sourceTaxAmount;
        $deductions['net_salary'] = Money::subtract($slip->net_salary, $sourceTaxAmount);
        $slip->forceFill([
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
        ]);

        if ($slip->exists) {
            $slip->saveQuietly();
        }
    }
}
