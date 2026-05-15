<?php

declare(strict_types=1);

namespace App\Filament\Resources\Spaces\Pages;

use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Spaces\SpaceResource;
use App\Models\Institution;
use App\Models\Space;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSpace extends EditRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = SpaceResource::class;

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
        $this->captureRelatedAuditSnapshot($this->spaceRecord());

        return $data;
    }

    protected function afterSave(): void
    {
        $this->auditRelatedStateChanges($this->spaceRecord(), 'relations_updated');
    }

    private function spaceRecord(): Space
    {
        $record = $this->getRecord();

        if (! $record instanceof Space) {
            throw new \RuntimeException('Expected Filament record to be a Space instance.');
        }

        return $record;
    }

    /**
     * @return array<string, list<array{id: string, name: string}>>
     */
    protected function getRelatedAuditSnapshot(Model $record): array
    {
        if (! $record instanceof Space) {
            return [];
        }

        return [
            'institutions' => $record->institutions()
                ->orderBy('institutions.name')
                ->get(['institutions.id', 'institutions.name'])
                ->map(fn (Institution $institution): array => [
                    'id' => (string) $institution->getKey(),
                    'name' => $institution->name,
                ])
                ->values()
                ->all(),
        ];
    }
}
