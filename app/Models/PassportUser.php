<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class PassportUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    #[\Override]
    protected $table = 'users';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
