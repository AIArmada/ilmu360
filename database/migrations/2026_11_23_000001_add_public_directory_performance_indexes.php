<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('events', ['status', 'visibility', 'published_at'], 'events_public_published_idx');

        $this->addIndex('references', ['record_kind', 'status', 'published_at', 'title'], 'references_public_root_title_idx');

        $this->addIndex(
            (string) config('events.database.tables.event_references', 'event_references'),
            ['referenceable_type', 'referenceable_id', 'event_id'],
            'event_references_target_event_idx',
        );

        $this->addIndex('persons', ['speaker_status', 'status', 'is_featured', 'order_column'], 'persons_public_featured_order_idx');

        $this->addIndex('social_accounts', ['user_id', 'updated_at'], 'social_accounts_user_updated_idx');
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }
};
