<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventType;
use Illuminate\Database\Seeder;

class EventTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $taxonomy = EventTaxonomy::query()->updateOrCreate(
            ['code' => 'event_type'],
            [
                'name' => 'Event Type',
                'description' => 'Classification of events by type (religious, community, educational, etc.)',
                'is_hierarchical' => false,
                'is_active' => true,
            ],
        );

        $sortOrder = 0;

        foreach (EventType::cases() as $eventType) {
            $group = $eventType->getGroup();

            EventTerm::query()->updateOrCreate(
                [
                    'event_taxonomy_id' => $taxonomy->id,
                    'code' => $eventType->value,
                ],
                [
                    'name' => $eventType->getLabel(),
                    'description' => null,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'metadata' => ['group' => $group],
                ],
            );

            $sortOrder++;
        }
    }
}
