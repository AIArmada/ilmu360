<?php

namespace App\Support\Api\Frontend;

use App\Enums\InstitutionType;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SearchRequestNormalizer
{
    public function __construct(
        private readonly PublicCountryRegistry $publicCountryRegistry,
    ) {}

    /**
     * @param  list<string>  $allowedFields
     * @return list<string>|null
     */
    public function requestedFields(Request $request, array $allowedFields, string $resourceLabel): ?array
    {
        $fields = $this->normalizedString($request->query('fields'));

        if ($fields === null) {
            return null;
        }

        $requestedFields = collect(explode(',', $fields))
            ->map(static fn (string $field): string => trim($field))
            ->filter(static fn (string $field): bool => $field !== '')
            ->unique()
            ->values()
            ->all();

        if ($requestedFields === []) {
            throw ValidationException::withMessages([
                'fields' => 'Provide at least one valid comma-separated '.$resourceLabel.' field name.',
            ]);
        }

        $unsupportedFields = array_values(array_diff($requestedFields, $allowedFields));

        if ($unsupportedFields !== []) {
            throw ValidationException::withMessages([
                'fields' => 'Unsupported '.$resourceLabel.' fields: '.implode(', ', $unsupportedFields).'. Supported fields: '.implode(', ', $allowedFields).'.',
            ]);
        }

        return $requestedFields;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $fields
     * @return array<string, mixed>
     */
    public function sparsePayload(array $payload, ?array $fields): array
    {
        if ($fields === null) {
            return $payload;
        }

        return collect($fields)
            ->mapWithKeys(fn (string $field): array => array_key_exists($field, $payload) ? [$field => $payload[$field]] : [])
            ->all();
    }

    /**
     * @return array{lat: ?float, lng: ?float}
     */
    public function resolvedNearbyCoordinates(Request $request): array
    {
        $lat = $this->normalizedLatitude($request->query('lat'));
        $lng = $this->normalizedLongitude($request->query('lng'));

        if ($lat !== null && $lng !== null) {
            return [
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        $nearCoordinates = $this->normalizedNearCoordinates($request->query('near'));

        return [
            'lat' => $nearCoordinates['lat'] ?? null,
            'lng' => $nearCoordinates['lng'] ?? null,
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function normalizedNearCoordinates(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $parts = preg_split('/\s*,\s*/', trim($value));

        if (! is_array($parts) || count($parts) !== 2) {
            return null;
        }

        $lat = $this->normalizedLatitude($parts[0]);
        $lng = $this->normalizedLongitude($parts[1]);

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    public function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    public function normalizedFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public function normalizedLatitude(mixed $value): ?float
    {
        $latitude = $this->normalizedFloat($value);

        return $latitude !== null && $latitude >= -90.0 && $latitude <= 90.0
            ? $latitude
            : null;
    }

    public function normalizedLongitude(mixed $value): ?float
    {
        $longitude = $this->normalizedFloat($value);

        return $longitude !== null && $longitude >= -180.0 && $longitude <= 180.0
            ? $longitude
            : null;
    }

    public function normalizedRadiusKm(Request $request): int
    {
        return max(1, min($request->integer('radius_km', 15), 100));
    }

    public function normalizedInt(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    public function normalizedInstitutionType(mixed $value): ?InstitutionType
    {
        $normalized = $this->normalizedString($value);

        if ($normalized === null) {
            return null;
        }

        return InstitutionType::tryFrom($normalized);
    }

    public function requestedCountryId(Request $request): ?int
    {
        return $this->publicCountryRegistry->resolveCountryId(
            $request->query('country_id'),
        );
    }
}
