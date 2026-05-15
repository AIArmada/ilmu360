<?php

namespace App\Actions\Subdistricts;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use App\Support\Location\FederalTerritoryLocation;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveSubdistrictAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Subdistrict $subdistrict = null): Subdistrict
    {
        $subdistrict ??= new Subdistrict;

        $countryId = $this->normalizeRequiredInteger($data['country_id'] ?? $subdistrict->country_id);
        $stateId = $this->normalizeRequiredInteger($data['state_id'] ?? $subdistrict->state_id);
        $districtId = array_key_exists('district_id', $data)
            ? $this->normalizeNullableInteger($data['district_id'])
            : $this->normalizeNullableInteger($subdistrict->district_id);
        $name = $this->normalizeRequiredString($data['name'] ?? $subdistrict->name, 'Subdistrict');

        $this->validateState($countryId, $stateId, $districtId);

        $subdistrict->fill([
            'country_id' => $countryId,
            'state_id' => $stateId,
            'district_id' => $districtId,
            'name' => $name,
            'country_code' => Country::query()->whereKey($countryId)->value('iso2'),
        ]);

        $subdistrict->save();

        return $subdistrict->fresh([
            'country',
            'state',
            'district',
        ]) ?? $subdistrict;
    }

    private function validateState(int $countryId, int $stateId, ?int $districtId): void
    {
        $errors = [];
        $state = State::query()->find($stateId);

        if (! $state instanceof State || (int) $state->country_id !== $countryId) {
            $errors['state_id'][] = __('Negeri yang dipilih tidak sepadan dengan negara yang dipilih.');
        }

        $isFederalTerritory = FederalTerritoryLocation::isFederalTerritoryStateId($stateId);

        if ($isFederalTerritory && $districtId !== null) {
            $errors['district_id'][] = __('Daerah tidak digunakan untuk negeri wilayah persekutuan.');
        }

        if (! $isFederalTerritory && $districtId === null) {
            $errors['district_id'][] = __('Sila pilih daerah untuk negeri yang dipilih.');
        }

        if ($districtId !== null) {
            $district = District::query()->find($districtId);

            if (! $district instanceof District
                || (int) $district->country_id !== $countryId
                || (int) $district->state_id !== $stateId) {
                $errors['district_id'][] = __('Daerah yang dipilih tidak sepadan dengan negeri dan negara yang dipilih.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function normalizeRequiredInteger(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : $fallback;
    }
}
