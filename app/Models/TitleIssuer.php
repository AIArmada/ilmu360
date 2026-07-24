<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssuerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleIssuer extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'country_id',
        'institution_id',
        'issuer_name',
        'issuer_type',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'issuer_type' => IssuerType::class,
        ];
    }
}
