<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

use App\Enums\InstitutionType;
use Lorisleiva\Actions\Concerns\AsAction;

final class InferInstitutionTypeAction
{
    use AsAction;

    /** @var array<string, list<string>> */
    private const array NameCues = [
        InstitutionType::Surau->value => ['surau', 'musalla', 'musallah', 'musala', 'prayer room', 'prayer hall'],
        InstitutionType::Masjid->value => ['masjid', 'mosque', 'jamek', 'jamik', 'jami'],
        InstitutionType::Madrasah->value => ['madrasah', 'madrasa'],
        InstitutionType::Maahad->value => ['maahad', 'ma had'],
        InstitutionType::Pondok->value => ['pondok'],
        InstitutionType::Universiti->value => ['universiti', 'university'],
        InstitutionType::Kolej->value => ['kolej', 'college'],
        InstitutionType::Sekolah->value => ['sekolah', 'school'],
    ];

    /** @var array<string, InstitutionType> */
    private const array GoogleTypeMap = [
        'mosque' => InstitutionType::Masjid,
        'school' => InstitutionType::Sekolah,
        'primary_school' => InstitutionType::Sekolah,
        'secondary_school' => InstitutionType::Sekolah,
        'preschool' => InstitutionType::Sekolah,
        'college' => InstitutionType::Kolej,
        'university' => InstitutionType::Universiti,
    ];

    /** @var list<string> */
    private const array NonInstitutionTypes = [
        'accounting',
        'airport',
        'amusement_park',
        'aquarium',
        'art_gallery',
        'atm',
        'bar',
        'beauty_salon',
        'cafe',
        'church',
        'dentist',
        'doctor',
        'food',
        'gym',
        'hospital',
        'lodging',
        'museum',
        'park',
        'pharmacy',
        'restaurant',
        'shopping_mall',
        'store',
        'tourist_attraction',
        'zoo',
    ];

    /**
     * @param  array<string, mixed>  $place
     */
    public function handle(array $place): ?InstitutionType
    {
        $displayName = $place['displayName'] ?? null;
        $name = is_array($displayName) && is_string($displayName['text'] ?? null)
            ? $displayName['text']
            : (is_string($displayName) ? $displayName : '');

        $primaryType = null;

        if (is_string($place['primaryType'] ?? null)) {
            $primaryType = mb_strtolower(trim($place['primaryType']));
        }

        $secondaryTypes = [];

        if (is_array($place['types'] ?? null)) {
            foreach ($place['types'] as $googleType) {
                if (is_string($googleType)) {
                    $secondaryTypes[] = mb_strtolower(trim($googleType));
                }
            }
        }

        if ($primaryType !== null && in_array($primaryType, self::NonInstitutionTypes, true)) {
            return null;
        }

        $primaryInstitutionType = $primaryType !== null ? (self::GoogleTypeMap[$primaryType] ?? null) : null;

        if ($primaryInstitutionType instanceof InstitutionType) {
            $nameType = $this->inferNameType($name);

            if ($this->isCompatibleNameRefinement($primaryInstitutionType, $nameType)) {
                return $nameType;
            }

            return $primaryInstitutionType;
        }

        foreach (array_filter([$primaryType, ...$secondaryTypes]) as $googleType) {
            $type = self::GoogleTypeMap[$googleType] ?? null;

            if ($type instanceof InstitutionType) {
                $nameType = $this->inferNameType($name);

                return $this->isCompatibleNameRefinement($type, $nameType) ? $nameType : $type;
            }
        }

        return $this->inferNameType($name);
    }

    private function inferNameType(string $name): ?InstitutionType
    {
        foreach (self::NameCues as $type => $cues) {
            if ($this->containsCue($name, $cues)) {
                return InstitutionType::from($type);
            }
        }

        return null;
    }

    private function isCompatibleNameRefinement(InstitutionType $providerType, ?InstitutionType $nameType): bool
    {
        if ($nameType === null) {
            return false;
        }

        if ($providerType === InstitutionType::Masjid) {
            return $nameType === InstitutionType::Surau;
        }

        if ($providerType === InstitutionType::Sekolah) {
            return in_array($nameType, [
                InstitutionType::Madrasah,
                InstitutionType::Maahad,
                InstitutionType::Pondok,
            ], true);
        }

        return $nameType === $providerType;
    }

    /**
     * @param  list<string>  $cues
     */
    private function containsCue(string $name, array $cues): bool
    {
        $normalized = mb_strtolower($name);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
        $normalized = is_string($normalized) ? trim($normalized) : '';
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        if (! is_string($normalized) || $normalized === '') {
            return false;
        }

        foreach ($cues as $cue) {
            $normalizedCue = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($cue));
            $normalizedCue = is_string($normalizedCue) ? trim($normalizedCue) : '';

            if ($normalizedCue !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($normalizedCue, '/').'(?![\p{L}\p{N}])/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
