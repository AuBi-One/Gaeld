<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Document => PDF layout key registered by a plugin (App\Support\Pdf\PdfLayouts); null = standard layouts.
            $table->json('pdf_layouts')->nullable()->after('invoice_footer_text');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('pdf_layouts');
        });
    }
};
