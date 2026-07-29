<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Persons\PersonResource;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Services\ContributionEntityMutationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof Person) {
            $record->loadMissing(['addresses']);
            $data['address'] = $this->addressFormState($record->primaryAddress());
        }

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $address = $data['address'] ?? null;
        unset($data['address']);

        if (is_array($address)) {
            $address['state_id'] ??= null;
            $address['city_id'] ??= null;

            if ($address['state_id'] === null) {
                $address['state'] = null;
                $address['city_id'] = null;
                $address['city'] = null;
            }
        }

        $record = parent::handleRecordUpdate($record, $data);

        if ($record instanceof Person && is_array($address)) {
            app(ContributionEntityMutationService::class)->syncPersonRelations($record, [
                'address' => $address,
            ]);
        }

        return $record;
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
            'area_assignments' => array_merge(
                ['administrative_district' => null, 'administrative_subdivision' => null, 'postal_locality' => null],
                $address?->areaAssignments()->pluck('address_area_id', 'role')->all() ?? [],
            ),
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
