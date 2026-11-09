<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

use App\Forms\SharedFormSchema;
use BackedEnum;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

final class MergeInstitutionMapsSelectionIntoFormAction
{
    use AsAction;

    /**
     * Apply verified suggestions while preserving values the contributor has edited.
     *
     * @param  array<string, mixed>  $formState
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>|null  $previousSelection
     * @return array{
     *     state: array<string, mixed>,
     *     autofilled_contacts: list<array<string, mixed>>,
     *     autofilled_social_profiles: list<array<string, mixed>>,
     *     autofilled_name: string|null,
     *     autofilled_type: string|null
     * }
     */
    public function handle(array $formState, array $selection, ?array $previousSelection = null): array
    {
        $previousSelection ??= [];
        $address = is_array($formState['address'] ?? null) ? $formState['address'] : [];
        $resolvedAddress = is_array($selection['address'] ?? null) ? $selection['address'] : [];
        $formState['address'] = array_merge($address, Arr::except($resolvedAddress, [
            'metadata',
            'components',
            'navigation_links',
        ]));

        $contacts = is_array($formState['contactMethods'] ?? null) ? $formState['contactMethods'] : [];
        $socialProfiles = is_array($formState['social_media'] ?? null) ? $formState['social_media'] : [];
        $autofilledContacts = is_array($selection['autofilled_contacts'] ?? null) ? $selection['autofilled_contacts'] : [];
        $autofilledSocialProfiles = is_array($selection['autofilled_social_profiles'] ?? null) ? $selection['autofilled_social_profiles'] : [];

        $formState['contactMethods'] = $this->replaceAutofilledRows(
            $contacts,
            $previousSelection['autofilled_contacts'] ?? [],
            $autofilledContacts,
        );
        $formState['social_media'] = $this->replaceAutofilledRows(
            $socialProfiles,
            $previousSelection['autofilled_social_profiles'] ?? [],
            $autofilledSocialProfiles,
        );

        $displayName = is_string($selection['display_name'] ?? null) ? $selection['display_name'] : null;
        $currentName = $formState['name'] ?? null;
        $previousAutofillName = $previousSelection['autofilled_name'] ?? null;

        if ($displayName !== null && (! is_string($currentName) || trim($currentName) === '' || $currentName === $previousAutofillName)) {
            $formState['name'] = $displayName;
        }

        $inferredType = is_string($selection['inferred_type'] ?? null) ? $selection['inferred_type'] : null;
        $currentType = $formState['type'] ?? null;
        $currentType = $currentType instanceof BackedEnum ? $currentType->value : $currentType;
        $previousAutofillType = $previousSelection['autofilled_type'] ?? null;
        $autofilledType = null;

        if ($inferredType !== null && (! is_string($currentType) || trim($currentType) === '' || $currentType === $previousAutofillType)) {
            $formState['type'] = $inferredType;
            $autofilledType = $inferredType;
        }

        return [
            'state' => $formState,
            'autofilled_contacts' => array_values($autofilledContacts),
            'autofilled_social_profiles' => array_values($autofilledSocialProfiles),
            'autofilled_name' => $displayName,
            'autofilled_type' => $autofilledType,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $currentRows
     * @param  list<array<string, mixed>>  $newAutofilledRows
     * @return list<array<string, mixed>>
     */
    private function replaceAutofilledRows(array $currentRows, mixed $previousAutofilledRows, array $newAutofilledRows): array
    {
        $previousRows = is_array($previousAutofilledRows) ? $previousAutofilledRows : [];
        $remainingRows = [];

        foreach ($currentRows as $row) {
            if (! is_array($row) || in_array($row, $previousRows, true)) {
                continue;
            }

            $remainingRows[] = $row;
        }

        $newAutofilledRows = array_values(array_filter(
            $newAutofilledRows,
            fn (mixed $row): bool => ! is_array($row) || ! $this->autofilledRowAlreadyPresent($row, $remainingRows),
        ));

        return array_values(array_merge($remainingRows, $newAutofilledRows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $remainingRows
     */
    private function autofilledRowAlreadyPresent(array $row, array $remainingRows): bool
    {
        foreach ($remainingRows as $existing) {
            if (isset($row['type']) || isset($existing['type'])) {
                if ($this->rowType($row) === $this->rowType($existing)
                    && $this->sameContactValue($row, $existing)) {
                    return true;
                }

                continue;
            }

            if (($row['platform'] ?? null) === ($existing['platform'] ?? null)
                && $this->sameSocialIdentity($row, $existing)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowType(array $row): ?string
    {
        $type = $row['type'] ?? null;

        return $type instanceof BackedEnum ? $type->value : (is_string($type) ? $type : null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $existing
     */
    private function sameContactValue(array $row, array $existing): bool
    {
        $candidate = $row['phone_value'] ?? $row['value'] ?? null;
        $current = $existing['phone_value'] ?? $existing['value'] ?? null;

        if (SharedFormSchema::isPhoneContactType($this->rowType($row))) {
            $candidate = SharedFormSchema::normalizedComparablePhoneValue($candidate);
            $current = SharedFormSchema::normalizedComparablePhoneValue($current);

            return $candidate !== null && $candidate === $current;
        }

        $candidate = is_string($candidate) ? trim($candidate) : null;
        $current = is_string($current) ? trim($current) : null;

        return $candidate !== null && $candidate !== '' && $candidate === $current;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $existing
     */
    private function sameSocialIdentity(array $row, array $existing): bool
    {
        $handle = is_string($row['handle'] ?? null) ? trim($row['handle']) : '';
        $existingHandle = is_string($existing['handle'] ?? null) ? trim($existing['handle']) : '';

        if ($handle !== '' && mb_strtolower($handle) === mb_strtolower($existingHandle)) {
            return true;
        }

        $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';
        $existingUrl = is_string($existing['url'] ?? null) ? trim($existing['url']) : '';

        return $url !== '' && $url === $existingUrl;
    }
}
