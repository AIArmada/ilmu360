<?php

namespace App\Livewire\Pages\Contributions;

use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Forms\SpeakerContributionFormSchema;
use App\Models\Speaker;
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
#[Title('Submit Speaker')]
class SubmitSpeaker extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithFileUploads;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->contributionForm()->fill([
            'gender' => 'male',
            'address' => [
                'country_id' => SharedFormSchema::preferredPublicCountryId(),
                'state_id' => null,
                'district_id' => null,
                'subdistrict_id' => null,
                'cascade_reset_guard' => 0,
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(new Speaker)
            ->statePath('data')
            ->components(SpeakerContributionFormSchema::components(
                includeMedia: true,
                addressStatePath: 'address',
                regionOnlyAddress: true,
                showCountryField: false,
            ));
    }

    public function submit(SubmitStagedContributionCreateAction $submitStagedContributionCreateAction): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $submittedName = data_get($this->data, 'name');
        $displayName = is_string($submittedName) && filled($submittedName)
            ? Speaker::formatDisplayedName(
                $submittedName,
                data_get($this->data, 'honorific'),
                data_get($this->data, 'pre_nominal'),
                data_get($this->data, 'post_nominal'),
            )
            : null;

        $submitStagedContributionCreateAction->handle(
            ContributionSubjectType::Speaker,
            $this->contributionForm()->getState(),
            $user,
            function (Speaker $speaker): void {
                $this->contributionForm()->model($speaker)->saveRelationships();
            },
            'data',
        );

        if (is_string($displayName) && filled($displayName)) {
            session()->flash('contribution_submission_name', $displayName);
        }

        $this->redirect(route('contributions.submission-success', [
            'subjectType' => ContributionSubjectType::Speaker->publicRouteSegment(),
        ]), navigate: true);
    }

    protected function contributionForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Speaker contribution form is not available.');
    }
}
