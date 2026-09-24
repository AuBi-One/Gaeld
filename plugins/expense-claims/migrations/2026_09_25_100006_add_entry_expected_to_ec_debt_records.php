<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database review N1: a debt record says whether it was booked with an entry
 * (false only when the conversion had nothing to move, e.g. claims already on
 * the debt account), so a debt whose entry was deleted (journal_entry_id
 * nulled by the foreign key) can be told from one created without an entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_debt_records', fn (Blueprint $table) => $table->boolean('entry_expected')->default(true));
        DB::statement('UPDATE ec_debt_records SET entry_expected = journal_entry_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('ec_debt_records', fn (Blueprint $table) => $table->dropColumn('entry_expected'));
    }
};
