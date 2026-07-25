<?php

declare(strict_types=1);

namespace App\Models;

use AIArmada\Persons\Models\Affiliation as BaseAffiliation;

/**
 * @property string $id
 * @property string $affiliatable_type
 * @property string $affiliatable_id
 * @property string|null $institution_id
 * @property string $affiliation_type
 * @property string|null $position
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $left_at
 * @property bool $is_primary
 */
class Affiliation extends BaseAffiliation
{
    /** @var list<string> */
    protected $fillable = [
        'affiliatable_type',
        'affiliatable_id',
        'institution_id',
        'affiliation_type',
        'position',
        'joined_at',
        'left_at',
        'is_primary',
    ];
}
