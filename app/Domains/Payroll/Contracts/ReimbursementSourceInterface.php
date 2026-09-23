<?php

namespace App\Domains\Payroll\Contracts;

use App\Domains\Payroll\Models\SalarySlip;

/**
 * Source of itemised expense reimbursements paid through payroll (e.g. an
 * expense-claims plugin). The default implementation offers no items, so the
 * payroll run keeps its manual reimbursement amount booked on the general
 * expense account.
 *
 * Item shape: id, date (Y-m-d), label, amount (decimal string) and, from
 * resolve(), account_code: the ledger account debited when the slip is posted
 * (e.g. the cost account, or a liability booked earlier). Optional splits
 * (list of account_code + amount, summing to amount) debit several accounts.
 */
interface ReimbursementSourceInterface
{
    /**
     * Items that can be reimbursed to this employee in a payroll run.
     *
     * @return list<array{id: string, date: string, label: string, amount: string}>
     */
    public function openItems(string $organizationId, string $employeeId): array;

    /**
     * Trusted amounts and accounts for the selected items.
     *
     * @param  list<string>  $itemIds
     * @return list<array{id: string, date: string, label: string, amount: string, account_code: string, splits?: list<array{account_code: string, amount: string}>}>
     *
     * @throws \DomainException When an item is unknown, not open or not owed to this employee
     */
    public function resolve(string $organizationId, string $employeeId, array $itemIds): array;

    /**
     * Mark the items as paid by this slip, for exactly the amounts booked.
     * Called in the posting transaction; must lock the items and throw a
     * \DomainException when one is no longer open for that amount.
     *
     * @param  list<array{id: string, date: string, label: string, amount: string, account_code: string}>  $items
     */
    public function settle(SalarySlip $slip, array $items): void;

    /**
     * Re-open the items paid by this slip. Called in the unposting transaction.
     */
    public function release(SalarySlip $slip): void;
}
