<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Footer line of generated PDFs; null = the default "© <year> Gäld".
            $table->string('pdf_footer_text')->nullable()->after('pdf_layouts');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('pdf_footer_text');
        });
    }
};
