<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Directory\Api\PersonWorkflow;
use App\Filament\Resources\Persons\PersonResource;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Support\Persons\PersonBioContent;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPerson extends EditRecord
{
    #[\Override]
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
        $data['bio'] = PersonBioContent::toRichContent($data['bio'] ?? null);

        $record = $this->getRecord();

        if ($record instanceof Person) {
            $record->loadMissing([
                'addresses',
                'languages',
                'media',
                'contactMethods',
                'socialProfiles',
                'titleAssignments.title',
                'members',
            ]);
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

        $actorId = auth()->id();

        app(PersonWorkflow::class)->saveFromAdmin([
            ...Arr::only($data, [
                'name',
                'middle_name',
                'family_name',
                'gender',
                'bio',
                'status',
                'speaker_status',
                'is_featured',
                'allow_public_event_submission',
            ]),
            'address' => $address,
        ], (string) $actorId, (string) $record->getKey());

        $record->refresh();

        return $record;
    }

    /**
     * @return array<int, Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->action(function (DeleteAction $action, Person $record): void {
                    app(PersonWorkflow::class)->delete([(string) $record->getKey()]);
                    $action->success();
                }),
        ];
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
            'google_place_id' => $address?->google_place_id,
            'google_feature_id' => $address?->google_feature_id,
            'google_cid' => $address?->google_cid,
            'google_entity_id' => $address?->google_entity_id,
            'waze_url' => $address?->waze_url,
        ]);
    }
}
