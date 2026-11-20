<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Series\Pages;

use AIArmada\FilamentCommerceSupport\Concerns\AuditsRelatedStateChanges;
use App\Features\Events\Api\Orm\SeriesAuthoring;
use App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource;
use App\Features\Events\Models\Series;
use App\Platform\Language\Models\Language;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateSeries extends CreateRecord
{
    use AuditsRelatedStateChanges;

    #[\Override]
    protected static string $resource = SeriesResource::class;

    /**
     * The authoring boundary receives language IDs and persists the relation;
     * Filament continues to manage the media collections.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $seriesId = app(SeriesAuthoring::class)->save(
            Arr::except($data, ['cover', 'gallery']),
        );

        return Series::query()->findOrFail($seriesId);
    }

    protected function afterCreate(): void
    {
        $this->auditRelatedStateChanges($this->seriesRecord(), 'relations_created');
    }

    private function seriesRecord(): Series
    {
        $record = $this->getRecord();

        if (! $record instanceof Series) {
            throw new \RuntimeException('Expected Filament record to be a Series instance.');
        }

        return $record;
    }

    /**
     * @return array<string, list<array{id: string, name: string}>>
     */
    protected function getRelatedAuditSnapshot(Model $record): array
    {
        if (! $record instanceof Series) {
            return [];
        }

        return [
            'languages' => $record->languages()
                ->orderBy('languages.name')
                ->get(['languages.id', 'languages.name'])
                ->map(fn (Language $language): array => [
                    'id' => (string) $language->getKey(),
                    'name' => $language->name,
                ])
                ->values()
                ->all(),
        ];
    }
}
