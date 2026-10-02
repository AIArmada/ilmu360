<?php

namespace App\Forms;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\Gender;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Models\Person;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonFormSchema
{
    /**
     * Shared createOptionForm for Person selects.
     *
     * @return array<int, Component>
     */
    public static function createOptionForm(): array
    {
        return PersonContributionFormSchema::components(
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
            $person = Person::create([
                'name' => $data['name'],
                'gender' => $data['gender'],
                'bio' => $data['bio'] ?? null,
                'slug' => app(GeneratePersonSlugAction::class)->handle((string) $data['name'], $data),
                'status' => 'pending',
            ]);

            $creator = auth()->user();

            if ($creator instanceof User) {
                AddMemberAction::run($person, $creator, MemberRole::Owner);
            }

            // Save media uploads (avatar/cover) via Filament's relationship-saving mechanism
            $schema?->model($person)->saveRelationships();

            app(ContributionEntityMutationService::class)->syncPersonRelations($person, $data);
            app(GeneratePersonSlugAction::class)->syncPersonSlug($person);

            return (string) $person->getKey();
        });
    }
}
