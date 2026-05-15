<?php

namespace App\Models;

use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Address extends Model implements AuditableContract
{
    use AuditsModelChanges, HasUuids;

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (self $address): void {
            if ($address->country_id === null) {
                $address->country_id = 132;
            }
        });
    }

    protected $fillable = [
        'addressable_type',
        'addressable_id',
        'type',
        'line1',
        'line2',
        'postcode',
        'country_id',
        'state_id',
        'district_id',
        'subdistrict_id',
        'city_id',
        'lat',
        'lng',
        'google_maps_url',
        'google_place_id',
        'waze_url',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function line1(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?string => self::normalizeTextValue($value),
            set: static fn (?string $value): ?string => self::normalizeTextValue($value),
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function line2(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?string => self::normalizeTextValue($value),
            set: static fn (?string $value): ?string => self::normalizeTextValue($value),
        );
    }

    private static function normalizeTextValue(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return BelongsTo<Subdistrict, $this>
     */
    public function subdistrict(): BelongsTo
    {
        return $this->belongsTo(Subdistrict::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
