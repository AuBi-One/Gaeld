<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;
use App\Support\Money;
use Plugins\ExpenseClaims\Models\Claim;

/**
 * Year-end closing: flags claims of the period that are not booked yet, and
 * booked claims still unpaid, which should be grouped into debt records
 * dated on the closing date before the year is locked.
 */
final class ClosingCheck implements ClosingCheckInterface
{
    public function check(string $organizationId, string $fromDate, string $toDate): array
    {
        $claims = Claim::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('date', '<=', $toDate)
            ->where(fn ($q) => $q->where('status', Claim::STATUS_APPROVED)
                // drafts only of this period; unpaid approved claims of earlier years count too
                ->orWhere(fn ($q) => $q->where('status', Claim::STATUS_DRAFT)->whereDate('date', '>=', $fromDate)))
            ->get(['status', 'total']);

        $findings = [];
        $drafts = $claims->where('status', Claim::STATUS_DRAFT);
        if ($drafts->isNotEmpty()) {
            $findings[] = [
                'key' => 'expense-claims.drafts',
                'message' => __('expense-claims::ec.closing_drafts', ['count' => $drafts->count()]),
                'action_label' => __('expense-claims::ec.open_claims'),
                'action_url' => '/payroll/expense-claims?status=draft',
            ];
        }

        $unpaid = $claims->where('status', Claim::STATUS_APPROVED);
        if ($unpaid->isNotEmpty()) {
            $findings[] = [
                'key' => 'expense-claims.unpaid',
                'message' => __('expense-claims::ec.closing_unpaid', [
                    'count' => $unpaid->count(),
                    'amount' => Money::sumAmounts($unpaid->map(fn (Claim $c): array => ['amount' => (string) $c->total])->values()->all()),
                ]),
                'action_label' => __('expense-claims::ec.convert_to_debt'),
                'action_url' => '/payroll/expense-balances?date='.$toDate,
            ];
        }

        return $findings;
    }
}
