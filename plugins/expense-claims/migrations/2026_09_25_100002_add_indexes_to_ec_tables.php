<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database review F2: indexes for the tenant scope, the foreign keys that are
 * followed on delete, and the lists and lock paths of the plugin (balances per
 * person, claims of a debt record, lines of a claim, items of a salary slip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_places', fn (Blueprint $table) => $table->index(['organization_id', 'kind']));
        Schema::table('ec_debt_records', fn (Blueprint $table) => $table->index(['organization_id', 'person_id', 'date']));
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->index('debt_record_id');
            $table->index('salary_slip_id');
        });
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->index(['organization_id', 'person_id', 'status']);
            $table->index('salary_slip_id');
            $table->index('debt_record_id');
        });
        Schema::table('ec_claim_lines', function (Blueprint $table) {
            $table->index(['claim_id', 'position']);
            $table->index('from_place_id');
            $table->index('to_place_id');
        });
        Schema::table('ec_distances', fn (Blueprint $table) => $table->index('to_place_id'));
    }

    public function down(): void
    {
        Schema::table('ec_distances', fn (Blueprint $table) => $table->dropIndex(['to_place_id']));
        Schema::table('ec_claim_lines', function (Blueprint $table) {
            $table->dropIndex(['to_place_id']);
            $table->dropIndex(['from_place_id']);
            $table->dropIndex(['claim_id', 'position']);
        });
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->dropIndex(['debt_record_id']);
            $table->dropIndex(['salary_slip_id']);
            $table->dropIndex(['organization_id', 'person_id', 'status']);
        });
        Schema::table('ec_debt_repayments', function (Blueprint $table) {
            $table->dropIndex(['salary_slip_id']);
            $table->dropIndex(['debt_record_id']);
        });
        Schema::table('ec_debt_records', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'person_id', 'date']));
        Schema::table('ec_places', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'kind']));
    }
};
