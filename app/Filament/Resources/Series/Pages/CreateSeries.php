<?php

declare(strict_types=1);

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Series\SeriesResource;
use App\Models\Series;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Nnjeim\World\Models\Language;

class CreateSeries extends CreateRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = SeriesResource::class;

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
     * @return array<string, list<array{id: int, name: string}>>
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
                    'id' => (int) $language->getKey(),
                    'name' => $language->name,
                ])
                ->values()
                ->all(),
        ];
    }
}
