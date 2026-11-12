<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\VenueType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Create or update a venue through the owning Directory feature.
 */
final readonly class SaveVenueAction
{
    use AsAction;

    public function __construct(
        private GenerateVenueSlugAction $generateVenueSlugAction,
        private SyncVenueMediaAction $syncVenueMedia,
        private SyncVenueFacilitiesAction $syncVenueFacilities,
        private SyncVenueRelationsAction $syncVenueRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Model $venue = null): Model
    {
        $creating = ! $venue instanceof Model;
        $venue ??= DirectoryModel::new('venue');

        if (! $creating) {
            DirectoryModel::assert($venue, 'venue');
        }

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];

        $venue->fill([
            'name' => $this->normalizeRequiredString($data['name'] ?? $venue->getAttribute('name'), 'Venue'),
            'venue_type' => array_key_exists('type', $data)
                ? $this->normalizeVenueType($data['type'] ?? null)
                : $this->normalizeVenueType($venue->getAttribute('venue_type')),
            'description' => array_key_exists('description', $data) ? $data['description'] : $venue->getAttribute('description'),
            'status' => array_key_exists('status', $data) ? (string) $data['status'] : ($creating ? 'verified' : (string) $venue->getAttribute('status')),
            'visibility' => array_key_exists('visibility', $data) ? (string) $data['visibility'] : ($creating ? 'public' : (string) ($venue->getAttribute('visibility') ?? 'public')),
        ]);

        $venueClass = DirectoryModel::venue();

        if ($creating) {
            if (! $venue->getKey()) {
                $venue->setAttribute($venue->getKeyName(), (string) Str::uuid());
            }

            $venue->setAttribute('slug', $this->generateVenueSlugAction->handle((string) $venue->getAttribute('name'), $address));
            $venueClass::withoutEvents(fn () => $venue->save());
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

        $this->syncVenueRelations->handle($venue, $relationPayload);
        $this->syncVenueFacilities($venue, $data);

        if ($this->containsMediaPayload($data)) {
            $this->syncVenueMedia->handle($venue, $data);
        }

        // Slug was calculated before the address was linked; re-sync now that
        // the address exists.
        if ($creating) {
            $venue = $venue->fresh(['addresses']) ?? $venue;
            $venue->setAttribute('slug', $this->generateVenueSlugAction->forVenue($venue));
            $venueClass::withoutEvents(fn () => $venue->saveQuietly());
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
    private function containsMediaPayload(array $data): bool
    {
        return ($data['clear_main'] ?? false) === true
            || ($data['clear_cover'] ?? false) === true
            || ($data['clear_gallery'] ?? false) === true
            || ($data['main'] ?? null) instanceof UploadedFile
            || ($data['cover'] ?? null) instanceof UploadedFile
            || is_array($data['gallery'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncVenueFacilities(Model $venue, array $data): void
    {
        if (! array_key_exists('facilities', $data)) {
            return;
        }

        $codes = $data['facilities'];

        $codes ??= [];

        if (! is_array($codes)) {
            return;
        }

        $codes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $code): mixed => is_string($code) ? trim($code) : $code, $codes),
            static fn (mixed $code): bool => is_string($code) && trim($code) !== '',
        )));

        $this->syncVenueFacilities->handle(
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
