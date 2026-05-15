<?php

declare(strict_types=1);

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Series\SeriesResource;
use App\Models\Series;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Nnjeim\World\Models\Language;

class EditSeries extends EditRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = SeriesResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->captureRelatedAuditSnapshot($this->seriesRecord());

        return $data;
    }

    protected function afterSave(): void
    {
        $this->auditRelatedStateChanges($this->seriesRecord(), 'relations_updated');
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
