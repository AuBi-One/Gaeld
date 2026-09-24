<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The organisation audit log (and the purge of an organisation's activity) filters on
 * `properties->>'organization_id'` (and orders by `created_at DESC`): an expression index
 * replaces the sequential scan with an index (or bitmap) scan. Postgres only (the
 * expression syntax); other drivers keep the sequential scan.
 */
return new class extends Migration
{
    private const INDEX = 'activity_log_organization_created_index';

    public function up(): void
    {
        if ($this->connection()->getDriverName() === 'pgsql') {
            $table = config('activitylog.table_name', 'activity_log');
            $this->connection()->statement('CREATE INDEX IF NOT EXISTS '.self::INDEX." ON {$table} ((properties->>'organization_id'), created_at DESC)");
        }
    }

    public function down(): void
    {
        if ($this->connection()->getDriverName() === 'pgsql') {
            $this->connection()->statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }

    // Same connection as the activity_log table itself (spatie/laravel-activitylog).
    private function connection(): ConnectionInterface
    {
        return DB::connection(config('activitylog.database_connection'));
    }
};
