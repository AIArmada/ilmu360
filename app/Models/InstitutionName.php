<?php

declare(strict_types=1);

namespace App\Models;

use App\Features\Directory\Api\InstitutionWorkflow;
use App\Shared\Directory\Enums\InstitutionNameType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $institution_id
 * @property InstitutionNameType $name_type
 * @property string $full_name
 * @property string $language_code
 * @property bool $is_primary
 * @property-read Institution $institution
 */
class InstitutionName extends Model
{
    use HasUuids;

    #[\Override]
    protected $table = 'institution_names';

    #[\Override]
    protected $fillable = [
        'institution_id',
        'name_type',
        'full_name',
        'language_code',
        'is_primary',
    ];

    protected static function booted(): void
    {
        static::saved(function (InstitutionName $name): void {
            app(InstitutionWorkflow::class)->enforceSinglePrimaryName(
                (string) $name->getKey(),
                (string) $name->institution_id,
                (bool) $name->is_primary,
            );
        });
    }

    protected function casts(): array
    {
        return [
            'name_type' => InstitutionNameType::class,
            'is_primary' => 'boolean',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return $this->table;
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}
