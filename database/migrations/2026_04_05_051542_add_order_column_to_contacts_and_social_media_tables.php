<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedInteger('order_column')->nullable()->index();
        });

        Schema::table('social_media', function (Blueprint $table): void {
            $table->unsignedInteger('order_column')->nullable()->index();
        });

        DB::transaction(function (): void {
            $this->backfillOrderColumns('contacts', 'contactable_type', 'contactable_id');
            $this->backfillOrderColumns('social_media', 'socialable_type', 'socialable_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });

        Schema::table('social_media', function (Blueprint $table): void {
            $table->dropColumn('order_column');
        });
    }

    private function backfillOrderColumns(string $table, string $groupTypeColumn, string $groupIdColumn): void
    {
        /** @var Collection<int, object> $rows */
        $rows = DB::table($table)
            ->select(['id', $groupTypeColumn, $groupIdColumn, 'created_at'])
            ->orderBy($groupTypeColumn)
            ->orderBy($groupIdColumn)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows
            ->groupBy(fn (object $row): string => ($row->{$groupTypeColumn} ?? '').'|'.($row->{$groupIdColumn} ?? ''))
            ->each(function (Collection $groupRows) use ($table): void {
                $order = 1;

                foreach ($groupRows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['order_column' => $order]);

                    $order++;
                }
            });
    }
};
