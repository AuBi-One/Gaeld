<?php

namespace App\Domains\Payroll\Contracts;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Pdf\PdfLayoutInterface;

/**
 * Renders the salary slip PDF (download and e-mail attachment) in place of
 * core's standard `exports.salary-slip` view.
 */
interface SalarySlipPdfLayoutInterface extends PdfLayoutInterface
{
    /** @return string The PDF document (binary) */
    public function renderSalarySlip(SalarySlip $slip, Organization $organization): string;
}
