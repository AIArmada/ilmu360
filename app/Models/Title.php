<?php

namespace App\Models;

use App\Enums\TitleUsagePosition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Title extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'short_form',
        'country_id',
        'language_code',
        'usage_position',
        'sort_order',
        'description',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'usage_position' => TitleUsagePosition::class,
        ];
    }

    /**
     * @return BelongsTo<TitleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TitleCategory::class);
    }

    /**
     * @return HasMany<TitleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TitleAssignment::class);
    }
}
