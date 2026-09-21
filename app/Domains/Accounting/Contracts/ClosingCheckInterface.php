<?php

namespace App\Domains\Accounting\Contracts;

/**
 * Extra, non-blocking check shown in step 2 of the year-end closing wizard.
 * Plugins register implementations with the container tag below.
 */
interface ClosingCheckInterface
{
    public const TAG = 'gaeld.closing_checks';

    /**
     * Findings for the fiscal period being closed; an empty list means nothing to report.
     *
     * @return list<array{key: string, message: string, action_label?: string, action_url?: string}>
     */
    public function check(string $organizationId, string $fromDate, string $toDate): array;
}
