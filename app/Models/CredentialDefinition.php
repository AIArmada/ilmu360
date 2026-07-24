<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialDefinition extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_form',
        'field',
        'credential_type',
        'language_code',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
        ];
    }
}
