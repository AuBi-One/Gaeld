<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->index(['organization_id', 'vehicle_type', 'valid_from']);
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
            $table->unique(['organization_id', 'employee_id']);
        });

        Schema::create('ec_debt_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('ec_people')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('account_code', 20);
            $table->uuid('journal_entry_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_debt_repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('debt_record_id')->constrained('ec_debt_records')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('via', 10); // bank, payroll
            $table->uuid('journal_entry_id')->nullable();
            $table->foreignUuid('salary_slip_id')->nullable()->constrained('salary_slips')->cascadeOnDelete();
            $table->timestamps();
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
            $table->uuid('journal_entry_id')->nullable();
            $table->string('settled_via', 10)->nullable(); // payroll, bank, debt
            $table->date('settled_on')->nullable();
            $table->uuid('settlement_entry_id')->nullable();
            $table->foreignUuid('salary_slip_id')->nullable()->constrained('salary_slips')->nullOnDelete();
            $table->foreignUuid('debt_record_id')->nullable()->constrained('ec_debt_records')->nullOnDelete();
            $table->json('attachments')->nullable();
            $table->string('source', 20)->default('manual'); // manual, airtable
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'date']);
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
        });

        Schema::create('ec_distances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('from_place_id')->constrained('ec_places')->cascadeOnDelete();
            $table->foreignUuid('to_place_id')->constrained('ec_places')->cascadeOnDelete();
            $table->decimal('km', 8, 1);
            $table->string('provider', 20);
            $table->timestamps();
            $table->unique(['from_place_id', 'to_place_id']);
        });
    }

    public function down(): void
    {
        foreach (['ec_distances', 'ec_claim_lines', 'ec_claims', 'ec_debt_repayments', 'ec_debt_records', 'ec_people', 'ec_places', 'ec_vehicle_rates', 'ec_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
