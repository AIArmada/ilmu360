<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TitleAssignment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'titleable_type',
        'titleable_id',
        'title_id',
        'issuer_id',
        'date_awarded',
        'date_expired',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'date_awarded' => 'immutable_date',
            'date_expired' => 'immutable_date',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function titleable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }
}
