<?php

namespace App\Domains\Accounting\Contracts;

/**
 * Extra check shown in step 2 of the year-end closing wizard. A finding is a
 * warning unless it sets `blocking: true`: then the wizard cannot advance and
 * the closing is refused (with the finding's message) until it is resolved.
 * Plugins register implementations with the container tag below.
 */
interface ClosingCheckInterface
{
    public const TAG = 'gaeld.closing_checks';

    /**
     * Findings for the fiscal period being closed; an empty list means nothing to report.
     *
     * @return list<array{key: string, message: string, action_label?: string, action_url?: string, blocking?: bool}>
     */
    public function check(string $organizationId, string $fromDate, string $toDate): array;
}
