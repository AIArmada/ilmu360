<?php

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Persons\SavePersonAction;
use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Persons\PersonResource;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Models\User;
use App\Support\Submission\PublicSubmissionUiEvents;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Nnjeim\World\Models\Language;

class EditPerson extends EditRecord
{
    use AuditsRelatedStateChanges;

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
        $this->captureRelatedAuditSnapshot($this->personRecord());

        $data['address'] = $this->addressFormState($this->personRecord()->primaryAddress());

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = $this->currentUser();

        if (! $actor instanceof User || ! $record instanceof Person) {
            abort(403);
        }

        return OwnerContext::withOwner(null, fn (): Person => app(SavePersonAction::class)->handle(
            $data,
            $actor,
            $record,
            'data.allow_public_event_submission',
        ));
    }

    protected function afterSave(): void
    {
        $this->auditRelatedStateChanges($this->personRecord(), 'relations_updated');
    }

    #[On(PublicSubmissionUiEvents::REFRESH_TOGGLE)]
    public function refreshPublicSubmissionToggleState(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->personRecord()->refresh();
            $this->refreshFormData(['allow_public_event_submission']);
        });
    }

    private function personRecord(): Person
    {
        $record = $this->getRecord();

        if (! $record instanceof Person) {
            throw new \RuntimeException('Expected Filament record to be a Person instance.');
        }

        return $record;
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, list<array{id: int, name: string}>>
     */
    protected function getRelatedAuditSnapshot(Model $record): array
    {
        if (! $record instanceof Person) {
            return [];
        }

        return [
            'languages' => $record->languages()
                ->orderBy('languages.name')
                ->get(['languages.id', 'languages.name'])
                ->map(fn (Language $language): array => [
                    'id' => (int) $language->getKey(),
                    'name' => $language->name,
                ])
                ->values()
                ->all(),
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
            'admin_area_1_id' => $address?->admin_area_1_id,
            'admin_area_2_id' => $address?->admin_area_2_id,
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
