<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliationRole extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliation_id',
        'role_name',
        'department',
        'start_date',
        'end_date',
        'is_current',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Affiliation, $this>
     */
    public function affiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class);
    }
}
