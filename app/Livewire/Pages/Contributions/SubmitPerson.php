<?php

namespace App\Livewire\Pages\Contributions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Persons\Enums\Gender;
use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Enums\ContributionSubjectType;
use App\Forms\PersonContributionFormSchema;
use App\Models\Person;
use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.app')]
#[Title('Submit Person')]
class SubmitPerson extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithFileUploads;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->contributionForm()->fill([
                'gender' => Gender::Male->value,
                'address' => [
                    'country_id' => null,
                    'state_id' => null,
                    'city_id' => null,
                    'admin_area_1_id' => null,
                    'admin_area_2_id' => null,
                    'cascade_reset_guard' => 0,
                ],
            ]);
        });
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(new Person)
            ->statePath('data')
            ->components(PersonContributionFormSchema::components(
                includeMedia: true,
                addressStatePath: 'address',
                regionOnlyAddress: true,
                showCountryField: false,
            ));
    }

    public function submit(SubmitStagedContributionCreateAction $submitStagedContributionCreateAction): void
    {
        OwnerContext::withOwner(null, function () use ($submitStagedContributionCreateAction): void {
            $user = auth()->user();

            abort_unless($user instanceof User, 403);

            $submitStagedContributionCreateAction->handle(
                ContributionSubjectType::Person,
                $this->contributionForm()->getState(),
                $user,
                function (Person $person): void {
                    $this->contributionForm()->model($person)->saveRelationships();
                    session()->flash('contribution_submission_name', $person->formatted_name);
                },
                'data',
            );

            $this->redirect(route('contributions.submission-success', [
                'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
            ]), navigate: true);
        });
    }

    protected function contributionForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Person contribution form is not available.');
    }
}
