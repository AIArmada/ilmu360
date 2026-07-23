<?php

namespace App\Models;

use App\Enums\AffiliationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Affiliation extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliatable_type',
        'affiliatable_id',
        'institution_id',
        'affiliation_type',
        'joined_at',
        'left_at',
        'is_primary',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'affiliation_type' => AffiliationType::class,
            'joined_at' => 'immutable_date',
            'left_at' => 'immutable_date',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function affiliatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<AffiliationRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(AffiliationRole::class);
    }
}
