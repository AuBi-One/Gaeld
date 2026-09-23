<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional reference of an invoice line to the record it was taken from
 * (e.g. a position of an offer, provided by a plugin). Kept on every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('source_type', 50)->nullable()->after('sort_order');
            $table->string('source_id', 64)->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
