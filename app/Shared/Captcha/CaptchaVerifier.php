<?php

declare(strict_types=1);

namespace App\Shared\Captcha;

interface CaptchaVerifier
{
    public function verify(?string $token, ?string $remoteIp = null): bool;
}
