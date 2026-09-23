<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The link offer line → invoice line now lives on the core invoice line
 * (`source_type = offer_line`, `source_id` = offer line id), so edits and
 * deletions in core are reflected. Existing plugin links are moved there,
 * matching each linked amount to a line of the same invoice with that amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_lines', 'source_type')) {
            throw new RuntimeException('Run the core migration add_source_to_invoice_lines_table first.');
        }

        $links = DB::table('of_offer_invoice_lines as ll')
            ->join('of_offer_invoices as oi', 'oi.id', '=', 'll.offer_invoice_id')
            ->whereNotNull('ll.offer_line_id')
            ->orderBy('ll.id')
            ->get(['oi.invoice_id', 'll.offer_line_id', 'll.amount']);

        $unmatched = 0;
        foreach ($links as $link) {
            $lineId = DB::table('invoice_lines')
                ->where('invoice_id', $link->invoice_id)
                ->whereNull('source_type')
                ->where('amount', $link->amount)
                ->orderBy('sort_order')
                ->value('id');
            if ($lineId !== null) {
                DB::table('invoice_lines')->where('id', $lineId)->update(['source_type' => 'offer_line', 'source_id' => (string) $link->offer_line_id]);
            } else {
                $unmatched++;
            }
        }
        if ($unmatched > 0) {
            // Edited invoice lines: that part of the offer counts as not invoiced; check the offers.
            Log::warning("offers: {$unmatched} offer-invoice link(s) without a matching invoice line were not moved");
        }

        Schema::dropIfExists('of_offer_invoice_lines');
        Schema::dropIfExists('of_offer_invoices');
    }

    public function down(): void
    {
        Schema::create('of_offer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('offer_id')->constrained('of_offers')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('of_offer_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_invoice_id')->constrained('of_offer_invoices')->cascadeOnDelete();
            $table->foreignId('offer_line_id')->nullable()->constrained('of_offer_lines')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->index('offer_line_id');
        });

        $rows = DB::table('invoice_lines as il')
            ->join('of_offer_lines as l', DB::raw('CAST(l.id AS TEXT)'), '=', 'il.source_id')
            ->where('il.source_type', 'offer_line')
            ->get(['il.invoice_id', 'l.offer_id', 'l.id as offer_line_id', 'il.amount']);
        foreach ($rows->groupBy('invoice_id') as $invoiceId => $lines) {
            if ($lines->pluck('offer_id')->unique()->count() > 1) {
                Log::warning("offers: invoice {$invoiceId} has lines from several offers; only the first offer is linked back");
                $lines = $lines->where('offer_id', $lines->first()->offer_id);
            }
            $linkId = DB::table('of_offer_invoices')->insertGetId([
                'offer_id' => $lines->first()->offer_id, 'invoice_id' => $invoiceId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                DB::table('of_offer_invoice_lines')->insert([
                    'offer_invoice_id' => $linkId, 'offer_line_id' => $line->offer_line_id, 'amount' => $line->amount, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('invoice_lines')->where('source_type', 'offer_line')->update(['source_type' => null, 'source_id' => null]);
    }
};
