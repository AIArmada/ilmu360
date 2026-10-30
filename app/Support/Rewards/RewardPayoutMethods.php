<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use Illuminate\Support\Str;

/**
 * Member-facing payout method options, derived from the configured manual
 * rail. Admin surfaces keep their own labels; this is the public contract
 * for what a member can actually register.
 */
final class RewardPayoutMethods
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $methods = config('rewards.payouts.methods', []);
        $methods = is_array($methods) ? $methods : [];

        $options = [];

        foreach ($methods as $method) {
            if (! is_string($method) || $method === '') {
                continue;
            }

            $options[$method] = Str::ucfirst(str_replace('_', ' ', $method));
        }

        return $options;
    }
}
