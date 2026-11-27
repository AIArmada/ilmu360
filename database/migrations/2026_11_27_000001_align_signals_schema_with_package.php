<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignAlertColumnsWithPackage();
        $this->addSignalEventIndexes();
    }

    private function alignAlertColumnsWithPackage(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $rulesTable = (string) config('signals.database.tables.alert_rules', 'signal_alert_rules');
        $deliveriesTable = (string) config('signals.database.tables.alert_deliveries', 'signal_alert_deliveries');

        $this->convertJsonbColumnToText($rulesTable, 'inline_destinations');
        $this->convertJsonbColumnToText($deliveriesTable, 'destination');
    }

    private function convertJsonbColumnToText(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $dataType = DB::table('information_schema.columns')
            ->whereRaw('table_schema = current_schema()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('data_type');

        if (! is_string($dataType) || $dataType !== 'jsonb') {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE text USING %s::text',
            $grammar->wrapTable($table),
            $grammar->wrap($column),
            $grammar->wrap($column),
        ));
    }

    private function addSignalEventIndexes(): void
    {
        $eventsTable = (string) config('signals.database.tables.events', 'signal_events');

        if (! Schema::hasTable($eventsTable)) {
            return;
        }

        if (! Schema::hasIndex($eventsTable, ['signal_session_id', 'id'])) {
            Schema::table($eventsTable, function (Blueprint $table): void {
                $table->index(['signal_session_id', 'id']);
            });
        }

        if (! Schema::hasIndex($eventsTable, ['signal_identity_id'])) {
            Schema::table($eventsTable, function (Blueprint $table): void {
                $table->index(['signal_identity_id']);
            });
        }
    }
};
