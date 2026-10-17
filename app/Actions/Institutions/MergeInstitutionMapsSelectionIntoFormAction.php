<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

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

        if (! is_string($currentType) || trim($currentType) === '' || $currentType === $previousAutofillType) {
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

        return array_values(array_merge($remainingRows, $newAutofilledRows));
    }
}
