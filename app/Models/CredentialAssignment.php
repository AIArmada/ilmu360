<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CredentialAssignment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'credentialable_type',
        'credentialable_id',
        'credential_id',
        'issuing_institution_id',
        'registration_number',
        'date_obtained',
        'date_expired',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'date_obtained' => 'immutable_date',
            'date_expired' => 'immutable_date',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function credentialable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<CredentialDefinition, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(CredentialDefinition::class);
    }
}
