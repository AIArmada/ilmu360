<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

final readonly class DonationChannelOptions
{
    public const array DUITNOW_ID_TYPES = [
        'business' => 'Business Registration Number (SSM)',
        'mobile' => 'Mobile Number',
    ];

    public const array EWALLET_PROVIDERS = [
        'tng' => "Touch 'n Go eWallet",
        'grab' => 'GrabPay',
        'shopee' => 'ShopeePay',
        'boost' => 'Boost',
        'bigpay' => 'BigPay',
    ];

    /** @return array<string, string> */
    public static function duitNowIdTypes(): array
    {
        return array_map(static fn (string $label): string => __($label), self::DUITNOW_ID_TYPES);
    }
}
