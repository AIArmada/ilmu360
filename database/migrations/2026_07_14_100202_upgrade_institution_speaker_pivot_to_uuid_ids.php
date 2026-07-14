<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'institution_speaker';

    private const string TEMPORARY_TABLE = 'institution_speaker_uuid_migration';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            if (Schema::hasTable(self::TEMPORARY_TABLE)) {
                Schema::rename(self::TEMPORARY_TABLE, self::TABLE);
            }

            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'id')) {
            return;
        }

        if (! str_contains(strtolower(Schema::getColumnType(self::TABLE, 'id')), 'int')) {
            $this->dropPairUniqueIndexes();

            return;
        }

        DB::transaction(function (): void {
            Schema::dropIfExists(self::TEMPORARY_TABLE);

            Schema::create(self::TEMPORARY_TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('institution_id')->index();
                $table->uuid('speaker_id')->index();
                $table->string('position')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->date('joined_at')->nullable();
                $table->timestamps();
            });

            DB::table(self::TABLE)
                ->orderBy('id')
                ->chunk(500, function ($rows): void {
                    $records = [];

                    foreach ($rows as $row) {
                        $records[] = [
                            'id' => (string) str()->uuid(),
                            'institution_id' => $row->institution_id,
                            'speaker_id' => $row->speaker_id,
                            'position' => $row->position,
                            'is_primary' => $row->is_primary,
                            'joined_at' => $row->joined_at,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }

                    if ($records !== []) {
                        DB::table(self::TEMPORARY_TABLE)->insert($records);
                    }
                });

            Schema::drop(self::TABLE);
            Schema::rename(self::TEMPORARY_TABLE, self::TABLE);
        });
    }

    /**
     * Remove only the obsolete institution/speaker pairwise unique index.
     */
    private function dropPairUniqueIndexes(): void
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $columns = array_map(
                static fn (mixed $column): string => (string) $column,
                $index['columns'] ?? [],
            );

            if (($index['unique'] ?? false) !== true || $columns !== ['institution_id', 'speaker_id']) {
                continue;
            }

            $name = $index['name'] ?? null;

            if (is_string($name) && $name !== '') {
                Schema::table(self::TABLE, function (Blueprint $table) use ($name): void {
                    $table->dropUnique($name);
                });
            }
        }
    }
};
