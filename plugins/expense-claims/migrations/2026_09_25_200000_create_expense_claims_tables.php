<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense-claims plugin schema, squashed before the first production migrate
 * (database review F11, gaeld-ops docs/REVIEW-database.md) from the ten files
 * of 2026-09-22 … 09-25 (create + nine alters): tables, the F1–F7 shape (foreign keys to journal_entries, contacts and salary
 * slips; tenant column on repayments; indexes; uniqueness) and N1 (entry_expected).
 * Every reference to a journal entry is ON DELETE SET NULL (the core convention;
 * the plugin then treats the record as not booked); salary slips that paid a
 * claim cannot be deleted (RESTRICT: unpost first); a person or a place keeps its
 * data when the linked contact goes (SET NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('expense_account_code', 20);
            $table->string('staff_liability_code', 20);
            $table->string('owner_liability_code', 20);
            $table->string('staff_debt_code', 20);
            $table->string('owner_debt_code', 20);
            $table->string('bank_account_code', 20);
            $table->timestamps();
        });

        Schema::create('ec_vehicle_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('vehicle_type', 20);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->decimal('rate_per_km', 8, 4);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'vehicle_type', 'valid_from']); // one rate per type and start date
        });

        Schema::create('ec_places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // hq, home, client, other
            $table->string('label');
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('CH');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamps();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index('contact_id');
            $table->index(['organization_id', 'kind']);
        });

        Schema::create('ec_people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->foreignUuid('home_place_id')->nullable()->constrained('ec_places')->nullOnDelete();
            $table->timestamps();
            // A person is an employee or a member (user) of the organisation.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['organization_id', 'employee_id']);
            $table->unique(['organization_id', 'user_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index('contact_id');
        });

        Schema::create('ec_debt_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('ec_people')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('account_code', 20);
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            // False only when the conversion had nothing to book (claims already on the debt account),
            // so a record whose entry was deleted can be told from one created without an entry.
            $table->boolean('entry_expected')->default(true);
            $table->index('journal_entry_id');
            $table->index(['organization_id', 'person_id', 'date']);
        });

        Schema::create('ec_debt_repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('debt_record_id')->constrained('ec_debt_records')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('via', 10); // bank, payroll
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('salary_slip_id')->nullable()->constrained('salary_slips')->restrictOnDelete();
            $table->timestamps();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->index('journal_entry_id');
            $table->index('debt_record_id');
            $table->index('salary_slip_id');
            $table->index(['organization_id', 'journal_entry_id']);
        });

        Schema::create('ec_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignUuid('person_id')->constrained('ec_people')->restrictOnDelete();
            $table->date('date');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft'); // draft, approved, settled, debt
            $table->decimal('total', 15, 2)->default(0);
            $table->string('liability_account_code', 20)->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('settled_via', 10)->nullable(); // payroll, bank, debt, migrated
            $table->date('settled_on')->nullable();
            $table->foreignUuid('settlement_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('salary_slip_id')->nullable()->constrained('salary_slips')->restrictOnDelete();
            $table->foreignUuid('debt_record_id')->nullable()->constrained('ec_debt_records')->nullOnDelete();
            $table->json('attachments')->nullable();
            $table->string('source', 20)->default('manual'); // manual, airtable
            $table->timestamps();
            // Id of the source record (e.g. Airtable "rec…") so imports are idempotent.
            $table->string('external_ref', 64)->nullable();
            // Who approved the claim and when (no user: approved by a migration).
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'external_ref']);
            $table->index(['organization_id', 'status', 'date']);
            $table->index(['organization_id', 'person_id', 'status']);
            $table->index('journal_entry_id');
            $table->index('settlement_entry_id');
            $table->index('salary_slip_id');
            $table->index('debt_record_id');
        });

        Schema::create('ec_claim_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('ec_claims')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('type', 20); // km, meal, accommodation, transport, other
            $table->string('description')->nullable();
            $table->foreignUuid('from_place_id')->nullable()->constrained('ec_places')->nullOnDelete();
            $table->foreignUuid('to_place_id')->nullable()->constrained('ec_places')->nullOnDelete();
            $table->boolean('round_trip')->nullable();
            $table->decimal('km_lookup', 8, 1)->nullable();
            $table->decimal('km', 8, 1)->nullable();
            $table->string('km_source', 10)->nullable(); // lookup, manual, migrated
            $table->string('km_override_reason')->nullable();
            $table->string('vehicle_type', 20)->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('expense_account_code', 20);
            $table->timestamps();
            $table->index(['claim_id', 'position']);
            $table->index('from_place_id');
            $table->index('to_place_id');
        });

        Schema::create('ec_distances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('from_place_id')->constrained('ec_places')->cascadeOnDelete();
            $table->foreignUuid('to_place_id')->constrained('ec_places')->cascadeOnDelete();
            $table->decimal('km', 8, 1);
            $table->string('provider', 20);
            $table->timestamps();
            $table->unique(['from_place_id', 'to_place_id']);
            $table->index('to_place_id');
        });
    }

    public function down(): void
    {
        foreach (['ec_distances', 'ec_claim_lines', 'ec_claims', 'ec_debt_repayments', 'ec_debt_records', 'ec_people', 'ec_places', 'ec_vehicle_rates', 'ec_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
