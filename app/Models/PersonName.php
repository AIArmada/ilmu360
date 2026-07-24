<?php

namespace App\Models;

use App\Enums\PersonNameType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonName extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
        'name_type',
        'full_name',
        'language_code',
        'is_primary',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'name_type' => PersonNameType::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
