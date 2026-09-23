<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;

/**
 * Runs the tagged year-end closing checks (plugins) for the wizard and the
 * closing action. A check that fails is reported and becomes a blocking
 * finding: for an accounting closing, a wrong refusal is safer than a
 * wrong close.
 */
final class ClosingChecks
{
    /**
     * @return list<array{key: string, message: string, action_label?: string, action_url?: string, blocking: bool}>
     */
    public function run(string $organizationId, string $fromDate, string $toDate): array
    {
        $findings = [];
        foreach (app()->tagged(ClosingCheckInterface::TAG) as $check) {
            $name = class_basename($check);
            try {
                $result = $check instanceof ClosingCheckInterface ? $check->check($organizationId, $fromDate, $toDate) : [];
            } catch (\Throwable $e) {
                report($e);
                $result = [[
                    'key' => 'closing-check-failed.'.$name,
                    'message' => (string) __('app.year_end_closing_check_failed', ['check' => $name]),
                    'blocking' => true,
                ]];
            }
            foreach ($result as $finding) {
                $findings[] = ['blocking' => (bool) ($finding['blocking'] ?? false)] + $finding;
            }
        }

        return $findings;
    }

    /**
     * The messages of the blocking findings.
     *
     * @return list<string>
     */
    public function blocking(string $organizationId, string $fromDate, string $toDate): array
    {
        return array_values(array_map(
            fn (array $finding): string => $finding['message'],
            array_filter($this->run($organizationId, $fromDate, $toDate), fn (array $finding): bool => $finding['blocking']),
        ));
    }
}
