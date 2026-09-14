<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capital_impacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('preservation_capital_id')
                ->constrained('preservation_capitals')
                ->cascadeOnDelete();
            $table->string('activity_name');
            $table->string('impact_type');
            $table->string('impact_direction')->default('adverse');
            $table->date('occurred_on');
            $table->text('value')->nullable();
            $table->string('unit')->nullable();
            $table->text('source_reference');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'preservation_capital_id', 'occurred_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_impacts');
    }
};
