<?php

namespace App\Forms;

use AIArmada\Persons\Enums\Gender;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Enums\SpeakerStatus;
use App\Models\Person;
use App\Services\Signals\ProductSignalsService;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

            $person = Person::create([
                'name' => $name,
                'gender' => $gender?->value,
                'bio' => $data['bio'] ?? null,
                'slug' => app(GeneratePersonSlugAction::class)->handle($name, $data),
                'status' => 'pending',
                'speaker_status' => SpeakerStatus::Inactive->value,
                'allow_public_event_submission' => false,
            ]);

            app(ProductSignalsService::class)->recordAuthorQuickCreated($person, request());

            return (string) $person->getKey();
        });
    }

    /**
     * Pending/verified persons available as authors (no speaker-only scope).
     *
     * @return array<string, string>
     */
    public static function searchOptions(string $search, int $limit = 50): array
    {
        $term = '%'.trim($search).'%';

        return self::labels(self::baseQuery()
            ->where(fn (Builder $query) => $query
                ->whereLike('name', $term)
                ->orWhereLike('middle_name', $term)
                ->orWhereLike('family_name', $term))
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get());
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

        return self::labels(self::baseQuery()->whereKey($values)->get());
    }

    /**
     * @return Builder<Person>
     */
    private static function baseQuery(): Builder
    {
        return Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->with('titleAssignments.title.category');
    }

    /**
     * @param  Collection<int, Person>|iterable<Person>  $persons
     * @return array<string, string>
     */
    private static function labels(iterable $persons): array
    {
        $rows = [];

        foreach ($persons as $person) {
            $formatted = trim((string) $person->formatted_name);
            $rows[(string) $person->getKey()] = [
                'label' => $formatted !== '' ? $formatted : trim((string) $person->name),
                'slug' => (string) $person->slug,
            ];
        }

        $counts = array_count_values(array_column($rows, 'label'));
        $labels = [];

        foreach ($rows as $id => $row) {
            $labels[$id] = ($counts[$row['label']] ?? 0) > 1
                ? $row['label'].' · '.$row['slug']
                : $row['label'];
        }

        return $labels;
    }
}
