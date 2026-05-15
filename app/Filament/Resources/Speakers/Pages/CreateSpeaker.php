<?php

namespace App\Filament\Resources\Speakers\Pages;

use App\Actions\Speakers\SaveSpeakerAction;
use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\Speaker;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Nnjeim\World\Models\Language;

class CreateSpeaker extends CreateRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = SpeakerResource::class;

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

        return app(SaveSpeakerAction::class)->handle(
            $data,
            $user,
            validationErrorKey: 'data.allow_public_event_submission',
        );
    }

    protected function afterCreate(): void
    {
        $this->auditRelatedStateChanges($this->speakerRecord(), 'relations_created');
    }

    private function speakerRecord(): Speaker
    {
        $record = $this->getRecord();

        if (! $record instanceof Speaker) {
            throw new \RuntimeException('Expected Filament record to be a Speaker instance.');
        }

        return $record;
    }

    /**
     * @return array<string, list<array{id: int, name: string}>>
     */
    protected function getRelatedAuditSnapshot(Model $record): array
    {
        if (! $record instanceof Speaker) {
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
