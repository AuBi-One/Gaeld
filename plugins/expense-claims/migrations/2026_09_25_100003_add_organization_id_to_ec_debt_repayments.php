<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database review F3: repayments become a tenant table like every other
 * record that holds money or a journal entry (backfilled from the debt record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_debt_repayments', fn (Blueprint $table) => $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete());
        DB::statement('UPDATE ec_debt_repayments r SET organization_id = d.organization_id FROM ec_debt_records d WHERE d.id = r.debt_record_id');
        DB::statement('ALTER TABLE ec_debt_repayments ALTER COLUMN organization_id SET NOT NULL');
        Schema::table('ec_debt_repayments', fn (Blueprint $table) => $table->index(['organization_id', 'journal_entry_id']));
    }

    public function down(): void
    {
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'journal_entry_id']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
