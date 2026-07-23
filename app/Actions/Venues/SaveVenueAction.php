<?php

namespace App\Actions\Venues;

use AIArmada\Events\Actions\SyncVenueFacilitiesAction;
use App\Enums\VenueType;
use App\Models\Venue;
use App\Services\ContributionEntityMutationService;
use App\Support\Media\ModelMediaSyncService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveVenueAction
{
    use AsAction;

    public function __construct(
        private ContributionEntityMutationService $contributionEntityMutationService,
        private GenerateVenueSlugAction $generateVenueSlugAction,
        private ModelMediaSyncService $mediaSyncService,
        private SyncVenueFacilitiesAction $syncVenueFacilitiesAction,
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
            'venue_type' => array_key_exists('type', $data)
                ? $this->normalizeVenueType($data['type'] ?? null)
                : $this->normalizeVenueType($venue->venue_type),
            'description' => array_key_exists('description', $data) ? $data['description'] : $venue->description,
            'status' => array_key_exists('status', $data) ? (string) $data['status'] : ($creating ? 'verified' : (string) $venue->status),
            'visibility' => array_key_exists('visibility', $data) ? (string) $data['visibility'] : ($creating ? 'public' : (string) ($venue->visibility ?? 'public')),
        ]);

        if ($creating) {
            if (! $venue->getKey()) {
                $venue->{$venue->getKeyName()} = (string) Str::uuid();
            }

            $venue->slug = $this->generateVenueSlugAction->handle($venue->name, $address);
            Venue::withoutEvents(fn () => $venue->save());
        } else {
            $venue->save();
        }

        $relationPayload = Arr::only($data, ['address', 'contactMethods', 'social_media']);

        if (array_key_exists('socialMedia', $data) && ! array_key_exists('social_media', $relationPayload)) {
            $relationPayload['social_media'] = $data['socialMedia'];
        }

        if (array_key_exists('socialProfiles', $data) && ! array_key_exists('social_media', $relationPayload)) {
            $relationPayload['social_media'] = $data['socialProfiles'];
        }

        $this->contributionEntityMutationService->syncVenueRelations($venue, $relationPayload);
        $this->syncVenueFacilities($venue, $data);
        $this->syncMedia($venue, $data);

        // ponytail: slug was calculated before address was linked; re-sync now that address exists.
        if ($creating) {
            $venue = $venue->fresh(['addresses']) ?? $venue;
            $venue->slug = $this->generateVenueSlugAction->forVenue($venue);
            Venue::withoutEvents(fn () => $venue->saveQuietly());
        }

        return $venue->fresh([
            'addresses',
            'contactMethods',
            'socialProfiles',
            'media',
        ]) ?? $venue;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Venue $venue, array $data): void
    {
        if (($data['clear_main'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($venue, 'main');
        }

        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($venue, 'cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($venue, 'gallery');
        }

        $main = $data['main'] ?? null;
        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $venue,
            $main instanceof UploadedFile ? $main : null,
            'main',
        );
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
     * @param  array<string, mixed>  $data
     */
    private function syncVenueFacilities(Venue $venue, array $data): void
    {
        if (! array_key_exists('facilities', $data)) {
            return;
        }

        $codes = $data['facilities'];

        if ($codes === null) {
            $codes = [];
        }

        if (! is_array($codes)) {
            return;
        }

        $codes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $code): mixed => is_string($code) ? trim($code) : $code, $codes),
            static fn (mixed $code): bool => is_string($code) && trim($code) !== '',
        )));

        $this->syncVenueFacilitiesAction->handle(
            $venue,
            array_map(static fn (string $code): array => ['code' => $code], $codes),
        );
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
