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
        Schema::create('preservation_capitals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('state_translator')->nullable();
            $table->string('unit')->nullable();
            $table->string('threshold')->nullable();
            $table->string('threshold_direction')->nullable();
            $table->text('source_reference')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preservation_capitals');
    }
};
