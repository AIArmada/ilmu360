<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Models;

use AIArmada\CommerceSupport\Models\SavedSearch as BaseSavedSearch;
use App\Features\SavedSearches\Actions\AssignSavedSearchUserMorphTypeAction;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SavedSearch extends BaseSavedSearch
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory, HasUuids;

    #[\Override]
    protected $table = 'saved_searches';

    #[\Override]
    protected $fillable = [
        'user_id', 'user_type', 'name', 'query', 'filters', 'meta',
        'radius_km', 'lat', 'lng', 'notify',
    ];

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'radius_km' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
        ]);
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(static function (self $savedSearch): void {
            app(AssignSavedSearchUserMorphTypeAction::class)->handle($savedSearch);
        });
    }

    protected static function newFactory(): SavedSearchFactory
    {
        return SavedSearchFactory::new();
    }
}
