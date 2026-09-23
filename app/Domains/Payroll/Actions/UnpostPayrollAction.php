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

        DB::transaction(function () use ($slip): void {
            if ($slip->journal_entry_id) {
                $slip->loadMissing('journalEntry.lines');
                if ($slip->journalEntry->is_posted) {
                    $reversal = $this->ledger->reverseEntry(
                        $slip->journalEntry,
                        "Unposting salary slip for {$slip->period_month}/{$slip->period_year}",
                    );
                    $this->ledger->postDraft($reversal);
                } else {
                    // Nothing was booked yet: drop the draft. Posting the slip again rebuilds
                    // the entry from the payroll calculation (e.g. migrated lines are replaced).
                    $this->ledger->deleteDraft($slip->journalEntry);
                }
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
