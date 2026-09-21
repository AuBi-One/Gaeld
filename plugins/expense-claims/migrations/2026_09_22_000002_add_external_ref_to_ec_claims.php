<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_claims', function (Blueprint $table) {
            // Id of the source record (e.g. Airtable "rec…") so imports are idempotent.
            $table->string('external_ref', 64)->nullable();
            $table->unique(['organization_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
