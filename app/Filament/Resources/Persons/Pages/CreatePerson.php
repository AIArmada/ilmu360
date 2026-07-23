<?php

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Persons\SavePersonAction;
use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Nnjeim\World\Models\Language;

class CreatePerson extends CreateRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = PersonResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            parent::mount();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return OwnerContext::withOwner(null, fn (): Model => app(SavePersonAction::class)->handle(
            $data,
            $user,
            validationErrorKey: 'data.allow_public_event_submission',
        ));
    }

    protected function afterCreate(): void
    {
        $this->auditRelatedStateChanges($this->personRecord(), 'relations_created');
    }

    private function personRecord(): Person
    {
        $record = $this->getRecord();

        if (! $record instanceof Person) {
            throw new \RuntimeException('Expected Filament record to be a Person instance.');
        }

        return $record;
    }

    /**
     * @return array<string, list<array{id: int, name: string}>>
     */
    protected function getRelatedAuditSnapshot(Model $record): array
    {
        if (! $record instanceof Person) {
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
