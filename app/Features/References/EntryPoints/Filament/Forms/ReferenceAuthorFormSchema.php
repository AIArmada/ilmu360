<?php

namespace App\Features\References\EntryPoints\Filament\Forms;

use AIArmada\Persons\Enums\Gender;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Api\SpeakerStatus;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Explicit author pathway for reference authorship.
 *
 * Unlike the speaker-oriented quick create, this never grants the submitter
 * ownership, never activates the person as a speaker, and never enables
 * event submission or directory visibility. Authorship alone grants nothing.
 */
class ReferenceAuthorFormSchema
{
    /**
     * @return array<int, Component>
     */
    public static function quickCreateForm(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255)
                ->autofocus(),
            Select::make('gender')
                ->label(__('Gender'))
                ->options(Gender::class)
                ->placeholder(__('Select gender'))
                ->native(false),
            RichEditor::make('bio')
                ->label(__('Biography'))
                ->helperText(__('Optional short background that helps tell same-named authors apart.'))
                ->json()
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function quickCreateUsing(array $data): string
    {
        return DB::transaction(function () use ($data): string {
            $rawName = $data['name'] ?? null;

            if (! is_string($rawName)) {
                throw ValidationException::withMessages(['name' => __('Enter the author name.')]);
            }

            $name = trim($rawName);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('Enter the author name.')]);
            }

            if (mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['name' => __('The author name must be at most 255 characters.')]);
            }

            $rawGender = $data['gender'] ?? null;
            $rawGender = $rawGender instanceof Gender ? $rawGender->value : (is_string($rawGender) ? trim($rawGender) : $rawGender);
            $gender = null;

            if ($rawGender !== null && $rawGender !== '') {
                $gender = is_string($rawGender) ? Gender::tryFrom($rawGender) : null;

                if (! $gender instanceof Gender) {
                    throw ValidationException::withMessages(['gender' => __('Select a valid gender or leave it blank.')]);
                }
            }

            $personId = app(PersonWorkflow::class)->createAuthor([
                'name' => $name,
                'gender' => $gender?->value,
                'bio' => $data['bio'] ?? null,
                'speaker_status' => SpeakerStatus::Inactive->value,
                'allow_public_event_submission' => false,
            ]);

            $person = app(PersonLookup::class)->find($personId);

            abort_if($person === null, 404);

            $signalRequest = request();
            $signalUser = $signalRequest->user();

            app(SignalRecorder::class)->record(
                eventName: 'author.quick_created',
                eventCategory: 'contribution',
                actorId: $signalUser instanceof User ? (string) $signalUser->getKey() : null,
                properties: [
                    'person_id' => $person->id,
                    'status' => (string) $person->status,
                    'source' => 'reference_author_picker',
                ],
                request: $signalRequest,
            );

            return $person->id;
        });
    }

    /**
     * Pending/verified persons available as authors (no speaker-only scope).
     *
     * @return array<string, string>
     */
    public static function searchOptions(string $search, int $limit = 50): array
    {
        return app(PersonLookup::class)->authorOptions($search, $limit);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public static function selectedLabels(array $values): array
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && Str::isUuid($value)));

        if ($values === []) {
            return [];
        }

        return app(PersonLookup::class)->authorLabels($values);
    }
}
