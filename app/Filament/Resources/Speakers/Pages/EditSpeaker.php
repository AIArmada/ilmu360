<?php

namespace App\Filament\Resources\Speakers\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Speakers\SaveSpeakerAction;
use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Forms\SharedFormSchema;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Location\AddressingCountryResolver;
use App\Support\Submission\PublicSubmissionUiEvents;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Nnjeim\World\Models\Language;

class EditSpeaker extends EditRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = SpeakerResource::class;

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
        $this->captureRelatedAuditSnapshot($this->speakerRecord());

        $data['address'] = $this->addressFormState($this->speakerRecord()->addressModel);

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = $this->currentUser();

        if (! $actor instanceof User || ! $record instanceof Speaker) {
            abort(403);
        }

        return OwnerContext::withOwner(null, fn (): Speaker => app(SaveSpeakerAction::class)->handle(
            $data,
            $actor,
            $record,
            'data.allow_public_event_submission',
        ));
    }

    protected function afterSave(): void
    {
        $this->auditRelatedStateChanges($this->speakerRecord(), 'relations_updated');
    }

    #[On(PublicSubmissionUiEvents::REFRESH_TOGGLE)]
    public function refreshPublicSubmissionToggleState(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->speakerRecord()->refresh();
            $this->refreshFormData(['allow_public_event_submission']);
        });
    }

    private function speakerRecord(): Speaker
    {
        $record = $this->getRecord();

        if (! $record instanceof Speaker) {
            throw new \RuntimeException('Expected Filament record to be a Speaker instance.');
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
        if (! $record instanceof Speaker) {
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
            ?? app(AddressingCountryResolver::class)->resolveId($address?->country_code);

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
