<?php

namespace App\Features\Persons\EntryPoints\Filament\Forms;

use AIArmada\Persons\Enums\Gender;
use App\Features\Contributions\Api\Filament\PersonContributionSchema;
use App\Features\Persons\Api\Filament\PersonQuickAdd;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonFormSchema implements PersonQuickAdd
{
    /**
     * @return array<int, Component>
     */
    public function formComponents(): array
    {
        return self::createOptionForm();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOption(array $data, ?Schema $schema = null): string
    {
        return self::createOptionUsing($data, $schema);
    }

    /**
     * Shared createOptionForm for Person selects.
     *
     * @return array<int, Component>
     */
    public static function createOptionForm(): array
    {
        return app(PersonContributionSchema::class)->contributionComponents(
            includeMedia: true,
            regionOnlyAddress: true,
            showCountryField: false,
        );
    }

    /**
     * Shared createOptionUsing callback for Person selects.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createOptionUsing(array $data, ?Schema $schema = null): string
    {
        $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null);
        $identity = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)],
        ])->validate();
        $data = array_replace($data, $identity);

        return DB::transaction(function () use ($data, $schema): string {
            $actorId = auth()->id();

            $personId = app(PersonWorkflow::class)->createFromForm(
                $data,
                is_string($actorId) ? $actorId : null,
                assignOwner: true,
            );

            $person = Person::query()->findOrFail($personId);

            // Save media uploads (avatar/cover) via Filament's relationship-saving mechanism
            $schema?->model($person)->saveRelationships();

            return $personId;
        });
    }
}
