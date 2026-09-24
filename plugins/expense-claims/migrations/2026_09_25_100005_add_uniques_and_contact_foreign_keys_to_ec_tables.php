<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database review F6/F7: one rate per vehicle type and start date; places and
 * people reference contacts with a foreign key. ON DELETE SET NULL: a place or
 * a person keeps its own address and name when the contact is force-deleted
 * or merged (the link is only a convenience for address look-ups), and a
 * contact must stay deletable from the Contacts screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_vehicle_rates', function (Blueprint $table) {
            $table->unique(['organization_id', 'vehicle_type', 'valid_from']);
            $table->dropIndex(['organization_id', 'vehicle_type', 'valid_from']); // covered by the unique
        });
        Schema::table('ec_places', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index('contact_id');
        });
        Schema::table('ec_people', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('ec_people', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropForeign(['contact_id']);
        });
        Schema::table('ec_places', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropForeign(['contact_id']);
        });
        Schema::table('ec_vehicle_rates', function (Blueprint $table) {
            $table->index(['organization_id', 'vehicle_type', 'valid_from']);
            $table->dropUnique(['organization_id', 'vehicle_type', 'valid_from']);
        });
    }
};
