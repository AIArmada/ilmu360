<?php

namespace App\Livewire\Pages\Contributions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Actions\Institutions\MergeInstitutionMapsSelectionIntoFormAction;
use App\Actions\Institutions\ResolveInstitutionMapsPlaceSelectionAction;
use App\Actions\Location\FetchGooglePlaceDetailsAction;
use App\Actions\Location\FetchGooglePlacePhotoPreviewsAction;
use App\Enums\ContributionSubjectType;
use App\Forms\InstitutionContributionFormSchema;
use App\Livewire\Concerns\InteractsWithLocationPickerSelection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    #[Locked]
    public ?string $verifiedMapsSelectionToken = null;

    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->contributionForm()->fill([
                'type' => null,
                'address' => [
                    'country_id' => null,
                    'state_id' => null,
                    'city_id' => null,
                    'area_assignments' => [],
                    'line1' => null,
                    'line2' => null,
                    'postcode' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'google_maps_url' => null,
                    'google_place_id' => null,
                    'google_feature_id' => null,
                    'google_cid' => null,
                    'google_entity_id' => null,
                    'google_display_name' => null,
                    'google_resolution_source' => null,
                    'google_resolution_status' => null,
                    'google_resolution_fingerprint' => null,
                    'google_resolution_message' => null,
                    'google_maps_normalization_enabled' => true,
                    'google_maps_remote_lookup_enabled' => false,
                    'cascade_reset_guard' => 0,
                    'waze_url' => null,
                ],
            ]);
        });
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
                mapsSelectionRequired: true,
            ));
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyPlaceSelection(
        string $statePath,
        array $selection,
        ResolveInstitutionMapsPlaceSelectionAction $resolveInstitutionMapsPlaceSelectionAction,
        MergeInstitutionMapsSelectionIntoFormAction $mergeInstitutionMapsSelectionIntoFormAction,
    ): array {
        if ($statePath !== 'data.address') {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Select an institution from the Google Maps search.'),
            ]);
        }

        $placeId = $selection['placeId'] ?? null;

        if (! is_string($placeId) || trim($placeId) === '') {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Select an institution from the Google Maps search.'),
            ]);
        }

        $previousSelection = $this->verifiedMapsSelection();
        $selectionData = $resolveInstitutionMapsPlaceSelectionAction->handle($placeId, $statePath.'.google_place_id');
        $merged = $mergeInstitutionMapsSelectionIntoFormAction->handle(
            is_array($this->data) ? $this->data : [],
            $selectionData,
            $previousSelection,
        );
        $this->data = $merged['state'];
        $this->resetValidation('data.address.google_place_id');

        $token = Str::random(48);
        $previousToken = $this->verifiedMapsSelectionToken;
        $this->verifiedMapsSelectionToken = $token;
        Cache::put($this->verifiedMapsSelectionCacheKey($token), [
            ...$selectionData,
            'address' => Arr::except($selectionData['address'], ['cascade_reset_guard']),
            'autofilled_contacts' => $merged['autofilled_contacts'],
            'autofilled_social_profiles' => $merged['autofilled_social_profiles'],
            'autofilled_name' => $merged['autofilled_name'],
            'autofilled_type' => $merged['autofilled_type'],
        ], now()->addMinutes(30));

        if (is_string($previousToken) && $previousToken !== '') {
            Cache::forget($this->verifiedMapsSelectionCacheKey($previousToken));
        }

        $user = auth()->user();

        if ($user instanceof User) {
            app(ProductSignalsService::class)->recordInstitutionMapsPlaceSelected(
                $user,
                $selectionData['website_url'] !== null,
                count($selectionData['autofilled_social_profiles']),
                request(),
            );
        }

        return [
            ...Arr::only($selectionData['address'], [
                'google_place_id',
                'google_display_name',
                'formatted_address',
                'latitude',
                'longitude',
            ]),
        ];
    }

    /**
     * @return list<array{
     *     preview_url: string,
     *     width: int|null,
     *     height: int|null,
     *     attributions: list<array{display_name: string|null, uri: string|null}>,
     *     google_maps_uri: string|null
     * }>
     */
    public function loadPlacePhotoPreviews(
        string $placeId,
        FetchGooglePlaceDetailsAction $fetchGooglePlaceDetailsAction,
        FetchGooglePlacePhotoPreviewsAction $fetchGooglePlacePhotoPreviewsAction,
    ): array {
        $verifiedSelection = $this->verifiedMapsSelection();

        if (! is_array($verifiedSelection)
            || ! is_string($verifiedSelection['place_id'] ?? null)
            || ! hash_equals($verifiedSelection['place_id'], $placeId)) {
            return [];
        }

        try {
            $place = $fetchGooglePlaceDetailsAction->handle($placeId, 'data.address.google_place_id');

            return $fetchGooglePlacePhotoPreviewsAction->handle(
                $placeId,
                is_array($place['photos'] ?? null) ? $place['photos'] : [],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function submit(SubmitStagedContributionCreateAction $submitStagedContributionCreateAction): void
    {
        OwnerContext::withOwner(null, function () use ($submitStagedContributionCreateAction): void {
            $user = auth()->user();

            abort_unless($user instanceof User, 403);

            $verifiedSelection = $this->verifiedMapsSelection();
            $submittedPlaceId = data_get($this->data, 'address.google_place_id');

            if (
                ! is_array($verifiedSelection)
                || ! is_string($submittedPlaceId)
                || ! is_string($verifiedSelection['place_id'] ?? null)
                || ! hash_equals($verifiedSelection['place_id'], $submittedPlaceId)
            ) {
                throw ValidationException::withMessages([
                    'data.address.google_place_id' => __('Select and verify the institution on Google Maps before submitting.'),
                ]);
            }

            $submittedName = data_get($this->data, 'name');
            $state = $this->contributionForm()->getState();
            $formAddress = is_array($state['address'] ?? null) ? $state['address'] : [];
            $verifiedAddress = is_array($verifiedSelection['address'] ?? null)
                ? $verifiedSelection['address']
                : [];
            $state['address'] = array_merge($formAddress, Arr::only($verifiedAddress, [
                'google_maps_url',
                'google_place_id',
                'google_feature_id',
                'google_cid',
                'google_entity_id',
                'google_display_name',
                'latitude',
                'longitude',
                'google_resolution_source',
                'google_resolution_status',
                'google_resolution_fingerprint',
                'google_resolution_message',
                'formatted_address',
                'components',
                'metadata',
                'navigation_links',
            ]));

            $submitStagedContributionCreateAction->handle(
                ContributionSubjectType::Institution,
                $state,
                $user,
                function (Institution $institution): void {
                    $this->contributionForm()->model($institution)->saveRelationships();
                },
                'data',
            );

            if (is_string($submittedName) && filled($submittedName)) {
                session()->flash('contribution_submission_name', $submittedName);
            }

            Cache::forget($this->verifiedMapsSelectionCacheKey($this->verifiedMapsSelectionToken));
            $this->verifiedMapsSelectionToken = null;

            $this->redirect(route('contributions.submission-success', [
                'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            ]), navigate: true);
        });
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

    /**
     * @return array<string, mixed>|null
     */
    private function verifiedMapsSelection(): ?array
    {
        if (! is_string($this->verifiedMapsSelectionToken) || $this->verifiedMapsSelectionToken === '') {
            return null;
        }

        $selection = Cache::get($this->verifiedMapsSelectionCacheKey($this->verifiedMapsSelectionToken));

        return is_array($selection) ? $selection : null;
    }

    private function verifiedMapsSelectionCacheKey(?string $token): string
    {
        return 'institution-contribution:verified-maps:'.hash('sha256', session()->getId().'|'.$this->getId().'|'.($token ?? ''));
    }
}
