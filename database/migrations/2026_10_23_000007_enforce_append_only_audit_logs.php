<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_log_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Audit logs are append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
            CREATE TRIGGER audit_logs_append_only
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
            DROP FUNCTION IF EXISTS prevent_audit_log_mutation();
        SQL);
    }
};
