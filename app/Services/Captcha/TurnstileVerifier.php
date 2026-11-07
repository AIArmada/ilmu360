<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Contracts\CaptchaVerifier;
use Illuminate\Support\Facades\Http;
use Throwable;

class TurnstileVerifier implements CaptchaVerifier
{
    public function isEnabled(): bool
    {
        return (bool) config('services.turnstile.enabled')
            && filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        $expectedAction = config('services.turnstile.expected_action');
        $expectedHostnames = config('services.turnstile.hostnames', []);

        if (! is_string($expectedAction) || $expectedAction === '' || ! is_array($expectedHostnames) || $expectedHostnames === []) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post((string) config('services.turnstile.verify_url'), [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);
        } catch (Throwable) {
            return false;
        }

        if (! $response->ok() || $response->json('success') !== true) {
            return false;
        }

        $action = $response->json('action');
        $hostname = $response->json('hostname');

        return is_string($action)
            && $action === $expectedAction
            && is_string($hostname)
            && in_array($hostname, $expectedHostnames, true);
    }
}
