<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class PassportUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    #[\Override]
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
