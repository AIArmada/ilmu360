<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;

class TurnstileVerifier
{
    public function isEnabled(): bool
    {
        return (bool) config('services.turnstile.enabled')
            && filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public function verify(?string $token, ?string $ipAddress = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        $response = Http::asForm()
            ->timeout(5)
            ->post((string) config('services.turnstile.verify_url'), [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ipAddress,
            ]);

        if (! $response->ok()) {
            return false;
        }

        return (bool) $response->json('success', false);
    }
}
