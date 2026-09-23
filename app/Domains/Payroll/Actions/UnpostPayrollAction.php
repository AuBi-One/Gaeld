<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\NullReimbursementSource;
use Illuminate\Support\Facades\DB;

/**
 * Reverts a posted salary slip back to draft so it can be corrected (fixed
 * gross salary, deductions, etc.) or deleted and regenerated. Reverses the
 * slip's journal entry the same way invoice/expense corrections do elsewhere
 * in the app.
 */
class UnpostPayrollAction
{
    public function __construct(
        private LedgerService $ledger,
        private ReimbursementSourceInterface $reimbursements = new NullReimbursementSource,
    ) {}

    public function execute(SalarySlip $slip): SalarySlip
    {
        if (! $slip->isPosted()) {
            throw new \DomainException('Only a posted salary slip can be unposted.');
        }

        // A draft entry (e.g. loaded by a data migration for review) may hold lines the payroll
        // calculator would not reproduce: post it in the journal first, then unpost the slip.
        if ($slip->journal_entry_id && $slip->journalEntry()->where('is_posted', false)->exists()) {
            throw new \DomainException(__('app.salary_slip_entry_is_draft'));
        }

        DB::transaction(function () use ($slip): void {
            if ($slip->journal_entry_id) {
                $slip->loadMissing('journalEntry.lines');
                $reversal = $this->ledger->reverseEntry(
                    $slip->journalEntry,
                    "Unposting salary slip for {$slip->period_month}/{$slip->period_year}",
                );
                $this->ledger->postDraft($reversal);
            }

            $slip->update([
                'journal_entry_id' => null,
                'posted_at' => null,
                'email_sent_at' => null,
            ]);

            $this->reimbursements->release($slip);
        });

        return $slip->fresh();
    }
}
