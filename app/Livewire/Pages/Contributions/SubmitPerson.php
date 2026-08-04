<?php

namespace App\Livewire\Pages\Contributions;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Persons\Enums\Gender;
use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Enums\ContributionSubjectType;
use App\Forms\PersonContributionFormSchema;
use App\Livewire\Concerns\InteractsWithLocationPickerSelection;
use App\Models\Person;
use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
    use InteractsWithLocationPickerSelection;
    use WithFileUploads;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->contributionForm()->fill([
                'gender' => Gender::Male->value,
                'address' => [
                    'country_id' => AddressCountry::query()->where('iso2', 'MY')->value('id'),
                    'state_id' => null,
                    'city_id' => null,
                    'area_assignments' => [],
                    'cascade_reset_guard' => 0,
                ],
            ]);
        });
    }

    public function form(Schema $schema): Schema
    {
        $sections = PersonContributionFormSchema::components(
            includeMedia: true,
            addressStatePath: 'address',
            regionOnlyAddress: true,
            showCountryField: true,
            includeAlternativeNames: true,
            useTitleMultiSelect: true,
            useInstitutionRepeater: true,
            splitProfileSections: true,
        );

        return $schema
            ->model(new Person)
            ->statePath('data')
            ->components([
                Tabs::make('PersonContributionTabs')
                    ->id('person-contribution-tabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Maklumat Utama'))
                            ->icon(Heroicon::User)
                            ->schema([$sections[0]]),
                        Tab::make(__('Maklumat Tambahan'))
                            ->icon(Heroicon::AcademicCap)
                            ->schema([$sections[1]]),
                        Tab::make(__('Afiliasi'))
                            ->icon(Heroicon::BuildingOffice)
                            ->schema([$sections[2]]),
                        Tab::make(__('Lokasi'))
                            ->icon(Heroicon::MapPin)
                            ->schema([$sections[3]]),
                        Tab::make(__('Hubungan'))
                            ->icon(Heroicon::ChatBubbleLeftRight)
                            ->schema([$sections[5], $sections[6]]),
                        Tab::make(__('Media'))
                            ->icon(Heroicon::Photo)
                            ->schema([$sections[4]]),
                    ]),
            ]);
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
