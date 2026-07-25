<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'native',
        'dir',
    ];
}
