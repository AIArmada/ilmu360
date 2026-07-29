<?php

namespace App\Filament\Resources\Institutions\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Institutions\SaveInstitutionAction;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\User;
use App\Support\Submission\PublicSubmissionUiEvents;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class EditInstitution extends EditRecord
{
    protected static string $resource = InstitutionResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    public function mount(int|string $record): void
    {
        OwnerContext::withOwner(null, function () use ($record): void {
            parent::mount($record);
        });
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['address'] = $this->addressFormState($this->institutionRecord()->primaryAddress());

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = $this->currentUser();

        if (! $actor instanceof User || ! $record instanceof Institution) {
            abort(403);
        }

        if (array_key_exists('address', $data) && is_array($data['address'])) {
            $data['address']['state_id'] ??= null;
            $data['address']['city_id'] ??= null;

            if ($data['address']['state_id'] === null) {
                $data['address']['state'] = null;
                $data['address']['city_id'] = null;
                $data['address']['city'] = null;
            }
        }

        return OwnerContext::withOwner(null, fn (): Institution => app(SaveInstitutionAction::class)->handle(
            $data,
            $actor,
            $record,
            'data.allow_public_event_submission',
        ));
    }

    #[On(PublicSubmissionUiEvents::REFRESH_TOGGLE)]
    public function refreshPublicSubmissionToggleState(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->institutionRecord()->refresh();
            $this->refreshFormData(['allow_public_event_submission']);
        });
    }

    private function institutionRecord(): Institution
    {
        $record = $this->getRecord();

        if (! $record instanceof Institution) {
            throw new \RuntimeException('Expected Filament record to be an Institution instance.');
        }

        return $record;
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressFormState(?Address $address): array
    {
        $countryId = SharedFormSchema::normalizeLocationId($address?->country_id)
            ?? app(AddressCountryResolver::class)->resolveId($address?->country_code);

        return SharedFormSchema::hydrateAddressFormState([
            'country_id' => $countryId,
            'state_id' => $address?->state_id,
            'city_id' => $address?->city_id,
            'state' => $address?->state,
            'city' => $address?->city,
            'area_assignments' => $address?->areaAssignments()->pluck('address_area_id', 'role')->all() ?? [],
            'line1' => $address?->line1,
            'line2' => $address?->line2,
            'postcode' => $address?->postcode,
            'latitude' => $address?->latitude,
            'longitude' => $address?->longitude,
            'google_maps_url' => $address?->google_maps_url,
            'provider_place_id' => $address?->provider_place_id,
            'waze_url' => $address?->waze_url,
        ]);
    }
}
