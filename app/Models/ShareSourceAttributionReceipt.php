<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShareSourceAttributionReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareSourceAttributionReceipt extends Model
{
    /** @use HasFactory<ShareSourceAttributionReceiptFactory> */
    use HasFactory;

    use HasUuids;

    public const string PROVENANCE_COOKIE = 'cookie';

    public const string PROVENANCE_CHECKOUT = 'checkout';

    public const string PROVENANCE_REGISTRATION = 'registration';

    public const string PROVENANCE_STAFF = 'staff';

    public const string PROVENANCE_SYSTEM = 'system';

    protected $fillable = [
        'namespace',
        'outcome_type',
        'source_transition_id',
        'business_key',
        'attribution_id',
        'provenance',
        'definition_version',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function isAttributed(): bool
    {
        return $this->attribution_id !== null;
    }

    protected static function newFactory(): ShareSourceAttributionReceiptFactory
    {
        return ShareSourceAttributionReceiptFactory::new();
    }
}
