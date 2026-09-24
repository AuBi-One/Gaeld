<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offers plugin schema, squashed before the first production migrate (database
 * review F11, gaeld-ops docs/REVIEW-database.md) from the four files of
 * 2026-09-23 … 09-25: templates with their layout, settings per organisation,
 * offers and lines, the review shape (indexes, one default template per
 * organisation, a foreign key for the creator, money at numeric(15,2) like
 * core). The offer → invoice link lives on the core invoice lines
 * (invoice_lines.source_type = offer_line, source_id = offer line id), so no
 * link table exists here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('of_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('intro')->nullable();
            $table->text('closing')->nullable();
            $table->json('lines')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->json('layout')->nullable(); // From/To boxes and the document layout
            $table->index('organization_id');
        });
        // One default template per organisation (Postgres partial unique index).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX of_templates_default_per_org ON of_templates (organization_id) WHERE is_default');
        }

        Schema::create('of_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('validity_months')->default(2);
            $table->string('sender_email')->nullable();
            $table->string('sender_phone', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('of_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 50);
            $table->string('status', 20)->default('draft');
            $table->foreignId('contact_id')->constrained('contacts'); // no action: an organisation delete cascades to both
            $table->foreignUuid('contact_person_id')->nullable()->constrained('contact_persons')->nullOnDelete();
            $table->json('recipient')->nullable();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->text('closing')->nullable();
            $table->text('notes')->nullable();
            $table->date('offer_date');
            $table->date('valid_until')->nullable();
            $table->date('request_date')->nullable();
            $table->string('language', 2)->default('fr');
            $table->string('currency', 3)->default('CHF');
            $table->unsignedBigInteger('vat_rate_id')->nullable();
            $table->foreign('vat_rate_id')->references('id')->on('vat_rates')->nullOnDelete();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignUuid('template_id')->nullable()->constrained('of_templates')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('supersedes_id')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('source', 20)->default('app');
            $table->string('external_ref')->nullable();
            $table->timestamps();
            $table->json('layout')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete(); // a real user, or none once deleted
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'contact_id']);
            $table->index(['organization_id', 'offer_date']); // list order and year filter
            $table->index('supersedes_id');
            $table->index('template_id');
            $table->index('contact_person_id');
            $table->index('vat_rate_id');
        });
        Schema::table('of_offers', function (Blueprint $table) {
            $table->foreign('supersedes_id')->references('id')->on('of_offers')->nullOnDelete();
        });

        Schema::create('of_offer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('offer_id')->constrained('of_offers')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('type', 10)->default('item');
            $table->string('label', 20)->nullable();
            $table->text('description');
            $table->decimal('quantity', 15, 2)->default(0);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->timestamps();
            $table->index(['offer_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('of_offer_lines');
        Schema::dropIfExists('of_offers');
        Schema::dropIfExists('of_settings');
        Schema::dropIfExists('of_templates');
    }
};
