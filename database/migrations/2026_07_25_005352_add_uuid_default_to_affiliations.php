<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE affiliations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER affiliations_fill_id AFTER INSERT ON affiliations
                BEGIN
                    UPDATE affiliations SET id = (
                        lower(hex(randomblob(4))) || '-' ||
                        lower(hex(randomblob(2))) || '-' ||
                        lower(hex(randomblob(2))) || '-' ||
                        lower(hex(randomblob(2))) || '-' ||
                        lower(hex(randomblob(6)))
                    ) WHERE id IS NULL AND rowid = NEW.rowid;
                END
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE affiliations ALTER COLUMN id DROP DEFAULT');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS affiliations_fill_id');
        }
    }
};
