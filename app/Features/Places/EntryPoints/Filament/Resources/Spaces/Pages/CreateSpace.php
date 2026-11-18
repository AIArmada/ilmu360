<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Resources\Spaces\Pages;

use AIArmada\FilamentCommerceSupport\Concerns\AuditsRelatedStateChanges;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource;
use App\Features\Institutions\Models\Institution;
use App\Features\Places\Models\Space;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSpace extends CreateRecord
{
    use AuditsRelatedStateChanges;

    #[\Override]
    protected static string $resource = SpaceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $spaceId = app(SpaceWorkflow::class)->saveFromAdmin($data);

        return Space::query()->findOrFail($spaceId);
    }

    protected function afterCreate(): void
    {
        $this->auditRelatedStateChanges($this->spaceRecord(), 'relations_created');
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
