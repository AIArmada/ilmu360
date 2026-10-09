<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms\Pages;

use App\Actions\Rewards\CreateRewardProgramAction;
use App\Enums\Rewards\RewardProgramMode;
use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use App\Models\RewardProgram;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateRewardProgram extends CreateRecord
{
    protected static string $resource = RewardProgramResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $program = CreateRewardProgramAction::run(
                (string) ($data['family_key'] ?? ''),
                (string) ($data['title'] ?? ''),
                RewardProgramMode::from((string) ($data['mode'] ?? '')),
                (string) ($data['owner_type'] ?? 'platform'),
                filled($data['owner_record_id'] ?? null) ? (string) $data['owner_record_id'] : null,
                (string) ($data['purpose'] ?? ''),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['family_key' => $exception->getMessage()]);
        }

        return $program;
    }

    protected function getRedirectUrl(): string
    {
        /** @var RewardProgram $record */
        $record = $this->getRecord();

        return RewardProgramResource::getUrl('view', ['record' => $record]);
    }
}
