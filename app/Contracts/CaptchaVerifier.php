<?php

declare(strict_types=1);

namespace App\Contracts;

interface CaptchaVerifier
{
    public function verify(?string $token, ?string $remoteIp = null): bool;
}
