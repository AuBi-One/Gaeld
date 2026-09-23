<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iteration 1b: validity per organisation (months), From/To layout per template,
 * several invoices per offer with the amount invoiced per offer line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('of_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('validity_months')->default(2);
            $table->string('sender_email')->nullable();
            $table->string('sender_phone', 50)->nullable();
            $table->timestamps();
        });

        Schema::table('of_templates', function (Blueprint $table) {
            $table->json('layout')->nullable();
            $table->dropColumn('validity_days');
        });

        Schema::table('of_offers', function (Blueprint $table) {
            $table->json('layout')->nullable();
        });

        Schema::create('of_offer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('offer_id')->constrained('of_offers')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('of_offer_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_invoice_id')->constrained('of_offer_invoices')->cascadeOnDelete();
            // Kept (line unknown) when a reopened draft's lines are replaced: invoice history stays.
            $table->foreignId('offer_line_id')->nullable()->constrained('of_offer_lines')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->index('offer_line_id');
        });

        // Offers invoiced whole before this change: one link, every item line in full.
        foreach (DB::table('of_offers')->whereNotNull('invoice_id')->get(['id', 'invoice_id']) as $offer) {
            $linkId = DB::table('of_offer_invoices')->insertGetId([
                'offer_id' => $offer->id, 'invoice_id' => $offer->invoice_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (DB::table('of_offer_lines')->where('offer_id', $offer->id)->where('type', 'item')->get(['id', 'amount']) as $line) {
                DB::table('of_offer_invoice_lines')->insert([
                    'offer_invoice_id' => $linkId, 'offer_line_id' => $line->id, 'amount' => $line->amount, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('of_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('of_offers', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->dropColumn('layout');
        });
        // Back to one invoice per offer: the first one created.
        foreach (DB::table('of_offer_invoices')->orderBy('id')->get(['offer_id', 'invoice_id'])->unique('offer_id') as $link) {
            DB::table('of_offers')->where('id', $link->offer_id)->update(['invoice_id' => $link->invoice_id]);
        }
        Schema::dropIfExists('of_offer_invoice_lines');
        Schema::dropIfExists('of_offer_invoices');
        Schema::table('of_templates', function (Blueprint $table) {
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->dropColumn('layout');
        });
        Schema::dropIfExists('of_settings');
    }
};
