<?php

namespace App\Livewire\Pages\Contributions;

use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Enums\ContributionSubjectType;
use App\Forms\InstitutionContributionFormSchema;
use App\Forms\SharedFormSchema;
use App\Livewire\Concerns\InteractsWithLocationPickerSelection;
use App\Models\Institution;
use App\Models\User;
use App\Support\Location\GooglePlacesConfiguration;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.app')]
class SubmitInstitution extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithLocationPickerSelection;
    use WithFileUploads;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->contributionForm()->fill([
            'type' => 'masjid',
            'address' => [
                'country_id' => SharedFormSchema::preferredPublicCountryId(),
                'state_id' => null,
                'district_id' => null,
                'subdistrict_id' => null,
                'line1' => null,
                'line2' => null,
                'postcode' => null,
                'lat' => null,
                'lng' => null,
                'google_maps_url' => null,
                'google_place_id' => null,
                'google_display_name' => null,
                'google_resolution_source' => null,
                'google_resolution_status' => null,
                'google_resolution_fingerprint' => null,
                'google_resolution_message' => null,
                'google_maps_normalization_enabled' => true,
                'google_maps_remote_lookup_enabled' => GooglePlacesConfiguration::isEnabled(),
                'cascade_reset_guard' => 0,
                'waze_url' => null,
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(new Institution)
            ->statePath('data')
            ->components(InstitutionContributionFormSchema::components(
                includeMedia: true,
                requireGoogleMaps: true,
                addressStatePath: 'address',
                includeLocationPicker: true,
            ));
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyPlaceSelection(array $selection, ResolveGooglePlaceSelectionAction $resolveGooglePlaceSelectionAction): array
    {
        return $this->applyLocationPickerSelection('data.address', $selection, $resolveGooglePlaceSelectionAction);
    }

    public function submit(SubmitStagedContributionCreateAction $submitStagedContributionCreateAction): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $submittedName = data_get($this->data, 'name');

        $submitStagedContributionCreateAction->handle(
            ContributionSubjectType::Institution,
            $this->contributionForm()->getState(),
            $user,
            function (Institution $institution): void {
                $this->contributionForm()->model($institution)->saveRelationships();
            },
            'data',
        );

        if (is_string($submittedName) && filled($submittedName)) {
            session()->flash('contribution_submission_name', $submittedName);
        }

        $this->redirect(route('contributions.submission-success', [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        ]), navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title(__('Submit Institution').' - '.config('app.name'));
        }
    }

    protected function contributionForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Institution contribution form is not available.');
    }
}
