<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_claims', function (Blueprint $table) {
            // Who approved the claim and when (no user: approved by a migration).
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->index(['organization_id', 'journal_entry_id']);
            $table->index(['organization_id', 'settlement_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ec_claims', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'journal_entry_id']);
            $table->dropIndex(['organization_id', 'settlement_entry_id']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
