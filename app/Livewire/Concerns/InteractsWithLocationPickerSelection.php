<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Forms\SharedFormSchema;

trait InteractsWithLocationPickerSelection
{
    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyLocationPickerSelection(
        string $statePath,
        array $selection,
        ResolveGooglePlaceSelectionAction $resolveGooglePlaceSelectionAction,
    ): array {
        $currentAddress = data_get($this, $statePath);
        $currentAddress = is_array($currentAddress) ? $currentAddress : [];
        $resolvedAddress = $resolveGooglePlaceSelectionAction->handle(array_merge($selection, [
            'fallbackCountryId' => $currentAddress['country_id'] ?? null,
        ]));

        data_set($this, $statePath, array_merge($currentAddress, $resolvedAddress, [
            'cascade_reset_guard' => SharedFormSchema::publicLocationPickerCascadeResetGuard(),
        ]));

        return $resolvedAddress;
    }
}
