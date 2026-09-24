<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database review F4: a salary slip that paid claims or debts cannot be
 * deleted (it must be unposted first, which releases them); before, deleting
 * the slip row orphaned the claims and silently dropped the repayments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->dropForeign(['salary_slip_id']);
            $table->foreign('salary_slip_id')->references('id')->on('salary_slips')->restrictOnDelete();
        });
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->dropForeign(['salary_slip_id']);
            $table->foreign('salary_slip_id')->references('id')->on('salary_slips')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->dropForeign(['salary_slip_id']);
            $table->foreign('salary_slip_id')->references('id')->on('salary_slips')->nullOnDelete();
        });
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->dropForeign(['salary_slip_id']);
            $table->foreign('salary_slip_id')->references('id')->on('salary_slips')->cascadeOnDelete();
        });
    }
};
