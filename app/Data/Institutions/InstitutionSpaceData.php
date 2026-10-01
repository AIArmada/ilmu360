<?php

declare(strict_types=1);

namespace App\Data\Institutions;

use App\Models\Space;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class InstitutionSpaceData extends Data
{
    public function __construct(
        public string $id,
        public ?int $capacity = null,
    ) {}

    /**
     * Catalog-space contract for institution links.
     *
     * Only existing catalog spaces (venue_id NULL, the canonical
     * SpaceEligibilityResolver::catalogQuery condition) may link to
     * institutions, mirroring SaveSpaceAction's universal prohibition of
     * venue-owned institution links. A provided pivot capacity must be at
     * least 1, matching SaveSpaceAction's capacity floor; null inherits
     * the space's own capacity.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'id' => ['required', 'uuid', Rule::exists((new Space)->getTable(), 'id')->whereNull('venue_id')],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
