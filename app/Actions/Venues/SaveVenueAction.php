<?php

namespace App\Actions\Venues;

use App\Enums\VenueType;
use App\Models\Venue;
use App\Services\ContributionEntityMutationService;
use App\Support\Media\ModelMediaSyncService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveVenueAction
{
    use AsAction;

    public function __construct(
        private ContributionEntityMutationService $contributionEntityMutationService,
        private GenerateVenueSlugAction $generateVenueSlugAction,
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Venue $venue = null): Venue
    {
        $creating = ! $venue instanceof Venue;
        $venue ??= new Venue;

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];

        $venue->fill([
            'name' => $this->normalizeRequiredString($data['name'] ?? $venue->name, 'Venue'),
            'type' => array_key_exists('type', $data)
                ? $this->normalizeVenueType($data['type'])
                : $this->normalizeVenueType($venue->type),
            'description' => array_key_exists('description', $data) ? $data['description'] : $venue->description,
            'status' => (string) ($data['status'] ?? $venue->status ?? ($creating ? 'verified' : '')),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($creating ? true : (bool) $venue->is_active),
            'facilities' => array_key_exists('facilities', $data)
                ? $this->normalizeFacilities($data['facilities'])
                : $this->normalizeFacilities($venue->facilities ?? []),
        ]);

        if ($creating) {
            $venue->slug = $this->generateVenueSlugAction->handle($venue->name, $address);
        }

        $venue->save();

        $relationPayload = Arr::only($data, ['address', 'contacts', 'social_media']);

        if (array_key_exists('socialMedia', $data) && ! array_key_exists('social_media', $relationPayload)) {
            $relationPayload['social_media'] = $data['socialMedia'];
        }

        $this->contributionEntityMutationService->syncVenueRelations($venue, $relationPayload);
        $this->syncMedia($venue, $data);

        return $venue->fresh([
            'address',
            'contacts',
            'socialMedia',
            'media',
        ]) ?? $venue;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Venue $venue, array $data): void
    {
        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($venue, 'cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($venue, 'gallery');
        }

        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $venue,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncMultiple(
            $venue,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeFacilities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $entry) {
            if (is_int($key)) {
                if (is_string($entry) && trim($entry) !== '') {
                    $normalized[trim($entry)] = true;
                }

                continue;
            }

            $facilityKey = trim($key);

            if ($facilityKey === '' || $entry === null || $entry === '') {
                continue;
            }

            $normalized[$facilityKey] = is_bool($entry) ? $entry : true;
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        $normalized = $this->normalizeOptionalString($value);

        return $normalized ?? $fallback;
    }

    private function normalizeVenueType(mixed $value): string
    {
        if ($value instanceof VenueType) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $value->value : VenueType::Dewan->value;
        }

        if (is_string($value) && VenueType::tryFrom($value) instanceof VenueType) {
            return $value;
        }

        return VenueType::Dewan->value;
    }
}
