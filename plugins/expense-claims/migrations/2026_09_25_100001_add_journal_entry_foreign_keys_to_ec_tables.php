<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database review F1: references to journal_entries get foreign keys (ON DELETE
 * SET NULL, the core convention), so a claim or debt whose entry disappears
 * loses the id instead of keeping a dangling one; the plugin then treats the
 * claim as not booked (Claim::isBooked()).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plain indexes on the referencing columns serve the ON DELETE SET NULL updates
        // (the existing composites led by organization_id do not).
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('settlement_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index('journal_entry_id');
            $table->index('settlement_entry_id');
        });
        Schema::table('ec_debt_records', function (Blueprint $table) {
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['organization_id', 'journal_entry_id']);
            $table->index('journal_entry_id');
        });
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->dropIndex(['journal_entry_id']);
            $table->dropForeign(['journal_entry_id']);
        });
        Schema::table('ec_debt_records', function (Blueprint $table) {
            $table->dropIndex(['journal_entry_id']);
            $table->dropIndex(['organization_id', 'journal_entry_id']);
            $table->dropForeign(['journal_entry_id']);
        });
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->dropIndex(['settlement_entry_id']);
            $table->dropIndex(['journal_entry_id']);
            $table->dropForeign(['settlement_entry_id']);
            $table->dropForeign(['journal_entry_id']);
        });
    }
};
