<?php

namespace App\Models;

use AIArmada\CommerceSupport\Models\SavedSearch as BaseSavedSearch;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SavedSearch extends BaseSavedSearch
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'user_type', 'name', 'query', 'filters', 'meta',
        'radius_km', 'lat', 'lng', 'notify',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'meta' => 'array',
            'radius_km' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $savedSearch) {
            $savedSearch->user_type ??= (new User)->getMorphClass();
        });
    }
}
