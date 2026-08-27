<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventTaxonomyCode;
use App\Enums\TaxonomyTerm\DisciplineTermCode;
use App\Enums\TaxonomyTerm\DomainTermCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class EventDisciplineSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $taxonomy = EventTaxonomy::query()->updateOrCreate(
                ['code' => EventTaxonomyCode::Discipline->value],
                [
                    'name' => 'Event Disciplines',
                    'description' => 'Specific fields of study for events.',
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            );

            $disciplineCodes = array_map(static fn (DisciplineTermCode $term): string => $term->value, DisciplineTermCode::cases());
            $existingTerms = EventTerm::query()
                ->where('event_taxonomy_id', $taxonomy->getKey())
                ->get(['id', 'code']);
            $staleTermIds = $existingTerms
                ->reject(fn (EventTerm $term): bool => in_array($term->code, $disciplineCodes, true))
                ->pluck('id');

            if ($staleTermIds->isNotEmpty()) {
                EventTerm::query()->whereIn('id', $staleTermIds)->update(['is_active' => false]);
            }

            $domainTaxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Domain->value)->value('id');
            $religiousDomainId = $domainTaxonomyId !== null
                ? EventTerm::query()
                    ->where('event_taxonomy_id', $domainTaxonomyId)
                    ->where('code', DomainTermCode::AgamaKerohanian->value)
                    ->value('id')
                : null;

            $termOrder = 0;

            foreach (DisciplineTermCode::cases() as $term) {
                $attributes = [
                    'parent_id' => null,
                    'name' => $term->label(),
                    'sort_order' => $termOrder++,
                    'is_active' => true,
                ];

                if ($religiousDomainId !== null) {
                    $attributes['metadata'] = ['domain_ids' => [$religiousDomainId]];
                }

                EventTerm::query()->updateOrCreate(
                    ['event_taxonomy_id' => $taxonomy->getKey(), 'code' => $term->value],
                    $attributes,
                );
            }

            $taxonomy->touch();
        });
    }
}
