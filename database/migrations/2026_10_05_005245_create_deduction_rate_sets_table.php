<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deduction_rate_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->date('date_from');
            $table->date('date_to');
            $table->timestamps();

            $table->index(['organization_id', 'code']);
        });

        Schema::table('deduction_rates', function (Blueprint $table) {
            $table->foreignId('deduction_rate_set_id')->nullable()->after('organization_id')
                ->constrained('deduction_rate_sets')->cascadeOnDelete();
        });

        // Backfill: any existing flat rates (from before headers existed) are
        // grouped into one "STANDARD" set per organization, covering the
        // current fiscal year, so nothing already configured is lost.
        $organizationIds = DB::table('deduction_rates')
            ->whereNull('deduction_rate_set_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $setId = DB::table('deduction_rate_sets')->insertGetId([
                'organization_id' => $organizationId,
                'code' => 'STANDARD',
                'title' => 'Standard',
                'date_from' => now()->startOfYear()->toDateString(),
                'date_to' => now()->endOfYear()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('deduction_rates')
                ->where('organization_id', $organizationId)
                ->whereNull('deduction_rate_set_id')
                ->update(['deduction_rate_set_id' => $setId]);
        }
    }

    public function down(): void
    {
        Schema::table('deduction_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deduction_rate_set_id');
        });

        Schema::dropIfExists('deduction_rate_sets');
    }
};
