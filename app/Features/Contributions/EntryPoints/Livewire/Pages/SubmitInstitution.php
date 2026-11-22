<?php

namespace App\Features\Contributions\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\Api\InstitutionMapsSelectionContext;
use App\Features\Contributions\Api\StagedContributionCreates;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelection;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelections;
use App\Features\Contributions\EntryPoints\Filament\Forms\InstitutionContributionFormSchema;
use App\Features\Contributions\Support\DonationQrFormUploads;
use App\Features\DonationChannels\Api\DonationQrWorkflow;
use App\Features\Institutions\Api\InstitutionMapsSelection;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Platform\Location\FetchGooglePlaceDetailsAction;
use App\Platform\Location\FetchGooglePlacePhotoPreviewsAction;
use App\Platform\Location\Ui\Concerns\InteractsWithLocationPickerSelection;
use App\Platform\Ui\Forms\DonationQrFields;
use App\Shared\Signals\SignalRecorder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
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
    use RestrictsFileUploadsToSchemaComponents;
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
                ],
            ]);
        });
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(Institution::class)
            ->statePath('data')
            ->components(InstitutionContributionFormSchema::components(
                includeMedia: true,
                requireGoogleMaps: true,
                addressStatePath: 'address',
                includeLocationPicker: true,
                mapsSelectionRequired: true,
                includeWazeUrlField: false,
            ));
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyPlaceSelection(
        string $statePath,
        array $selection,
        InstitutionMapsSelection $institutionMapsSelection,
        VerifiedInstitutionMapsSelections $verifiedMapsSelections,
    ): array {
        $this->hitMapsThrottle('select');

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
        $selectionData = $institutionMapsSelection->resolve($placeId, $statePath.'.google_place_id');
        $merged = $institutionMapsSelection->merge(
            is_array($this->data) ? $this->data : [],
            $selectionData,
            $previousSelection,
        );
        $this->data = $merged['state'];
        $this->resetValidation('data.address.google_place_id');

        $token = Str::random(48);
        $previousToken = $this->verifiedMapsSelectionToken;
        $this->verifiedMapsSelectionToken = $token;
        $verifiedMapsSelections->replace(
            InstitutionMapsSelectionContext::create(),
            session()->getId(),
            $this->getId(),
            $token,
            VerifiedInstitutionMapsSelection::fromResolvedSelection($selectionData, $merged),
            $previousToken,
        );

        $user = auth()->user();

        if ($user instanceof User) {
            app(SignalRecorder::class)->record(
                eventName: 'contribution.institution_maps_place_selected',
                eventCategory: 'contribution',
                actorId: (string) $user->getKey(),
                properties: [
                    'has_website' => $selectionData['website_url'] !== null,
                    'social_profile_count' => count($selectionData['autofilled_social_profiles']),
                ],
                request: request(),
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
        $this->hitMapsThrottle('photos');

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

    /**
     * Rate-limit paid Google Maps calls per contributor. Human selection pace
     * stays far below the cap; only abusive automation trips it.
     */
    private function hitMapsThrottle(string $action): void
    {
        $key = 'maps-'.$action.':'.(auth()->id() ?? session()->getId());

        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Please wait a moment before trying again.'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    public function submit(StagedContributionCreates $stagedCreates): void
    {
        OwnerContext::withOwner(null, function () use ($stagedCreates): void {
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

            // Fail expired QR uploads before getState(): Filament crashes on
            // missing temporary files, and the create wraps persistence in a
            // transaction that would strand orphaned media files on disk.
            $rawDonationRows = $this->data['donation_channels'] ?? null;
            DonationQrFormUploads::assertFresh($rawDonationRows);

            $state = $this->contributionForm()->getState();
            $donationQrUploads = DonationQrFields::rekeyDonationRowsByRawKeys(
                DonationQrFields::collectDonationQrUploads($state['donation_channels'] ?? null),
                $rawDonationRows,
            );
            $state = DonationQrFields::withoutDonationQrUploads($state);
            $submittedDonationRows = DonationQrFields::rekeyDonationRowsByRawKeys(
                is_array($state['donation_channels'] ?? null) ? $state['donation_channels'] : [],
                $rawDonationRows,
            );
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

            try {
                $stagedCreates->submit(
                    ContributionSubjectType::Institution,
                    $state,
                    (string) $user->getKey(),
                    fn (string $institutionId) => app(InstitutionWorkflow::class)->withContributionModel(
                        $institutionId,
                        function (Institution $institution) use ($submittedDonationRows, $donationQrUploads): void {
                            $this->contributionForm()->model($institution)->saveRelationships();

                            if ($donationQrUploads !== []) {
                                app(DonationQrWorkflow::class)->attachDonationQrUploads(
                                    $institution->getMorphClass(),
                                    (string) $institution->getKey(),
                                    $submittedDonationRows,
                                    DonationQrFormUploads::prepare($donationQrUploads),
                                );
                            }
                        },
                    ),
                    'data',
                );
            } catch (LockTimeoutException) {
                throw ValidationException::withMessages([
                    'data.address.google_place_id' => __('The review service is busy. Please try again in a moment.'),
                ]);
            }

            if (is_string($submittedName) && filled($submittedName)) {
                session()->flash('contribution_submission_name', $submittedName);
            }

            app(VerifiedInstitutionMapsSelections::class)->forget(
                InstitutionMapsSelectionContext::create(),
                session()->getId(),
                $this->getId(),
                $this->verifiedMapsSelectionToken,
            );
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

        return app(VerifiedInstitutionMapsSelections::class)
            ->get(
                InstitutionMapsSelectionContext::create(),
                session()->getId(),
                $this->getId(),
                $this->verifiedMapsSelectionToken,
            )
            ?->toArray();
    }
}
