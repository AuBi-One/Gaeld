<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Models\SalarySlip;

/**
 * Default: no itemised reimbursements.
 */
final class NullReimbursementSource implements ReimbursementSourceInterface
{
    public function openItems(string $organizationId, string $employeeId): array
    {
        return [];
    }

    public function resolve(string $organizationId, string $employeeId, array $itemIds): array
    {
        if ($itemIds !== []) {
            throw new \DomainException('No reimbursement source is installed.');
        }

        return [];
    }

    public function settle(SalarySlip $slip, array $items): void {}

    public function release(SalarySlip $slip): void {}
}
