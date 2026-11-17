<?php

declare(strict_types=1);

namespace App\Platform\Captcha;

use App\Shared\Captcha\CaptchaVerifier;

/**
 * Null-object captcha verifier for environments without Turnstile configured.
 */
final class NullCaptchaVerifier implements CaptchaVerifier
{
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        return true;
    }
}
