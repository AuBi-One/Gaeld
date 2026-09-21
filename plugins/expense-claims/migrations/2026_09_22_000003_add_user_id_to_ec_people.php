<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_people', function (Blueprint $table) {
            // A person is an employee or a member (user) of the organisation.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ec_people', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
