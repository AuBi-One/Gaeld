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
        Schema::create('preservation_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('preservation_capital_id')
                ->constrained('preservation_capitals')
                ->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('action_type');
            $table->string('status')->default('planned');
            $table->date('due_on')->nullable();
            $table->text('source_reference')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'action_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preservation_actions');
    }
};
