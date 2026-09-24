<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database review (gaeld-ops docs/REVIEW-database.md, F2/F5/F7/F8): indexes for the
 * scoped queries, one default template per organisation (partial unique index),
 * a foreign key for the creator, money columns at numeric(15,2) like core.
 */
return new class extends Migration
{
    private const MONEY = [
        'of_offers' => ['subtotal', 'vat_amount', 'total'],
        'of_offer_lines' => ['quantity', 'unit_price', 'amount', 'vat_amount'],
    ];

    public function up(): void
    {
        Schema::table('of_templates', fn (Blueprint $t) => $t->index('organization_id'));
        Schema::table('of_offer_lines', fn (Blueprint $t) => $t->index(['offer_id', 'sort']));
        Schema::table('of_offers', function (Blueprint $t): void {
            $t->index(['organization_id', 'offer_date']); // list order and year filter
            $t->index('supersedes_id');
            $t->index('template_id');
            $t->index('contact_person_id');
            $t->index('vat_rate_id');
        });

        // One default template per organisation (Postgres partial unique index): where an
        // organisation has several, keep the most recently updated one (NULL timestamps last).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE of_templates SET is_default = false WHERE id IN (SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY organization_id ORDER BY updated_at DESC NULLS LAST, id DESC) AS rn FROM of_templates WHERE is_default) ranked WHERE rn > 1)');
            DB::statement('CREATE UNIQUE INDEX of_templates_default_per_org ON of_templates (organization_id) WHERE is_default');
        }

        // Creator: a real user, or none once the user is deleted.
        DB::table('of_offers')->whereNotNull('created_by')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.id', 'of_offers.created_by'))
            ->update(['created_by' => null]);
        Schema::table('of_offers', fn (Blueprint $t) => $t->foreign('created_by')->references('id')->on('users')->nullOnDelete());

        // numeric(12,2) → numeric(15,2): a widening, no value changes (Postgres does not rewrite the rows).
        foreach (self::MONEY as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(15,2)");
            }
        }
    }

    public function down(): void
    {
        // Narrowing back fails if a value no longer fits numeric(12,2) (>= 10^10): none is expected (the code clamps lower).
        foreach (self::MONEY as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE numeric(12,2)");
            }
        }
        Schema::table('of_offers', fn (Blueprint $t) => $t->dropForeign(['created_by']));
        DB::statement('DROP INDEX IF EXISTS of_templates_default_per_org');
        Schema::table('of_offers', function (Blueprint $t): void {
            $t->dropIndex(['organization_id', 'offer_date']);
            $t->dropIndex(['supersedes_id']);
            $t->dropIndex(['template_id']);
            $t->dropIndex(['contact_person_id']);
            $t->dropIndex(['vat_rate_id']);
        });
        Schema::table('of_offer_lines', fn (Blueprint $t) => $t->dropIndex(['offer_id', 'sort']));
        Schema::table('of_templates', fn (Blueprint $t) => $t->dropIndex(['organization_id']));
    }
};
