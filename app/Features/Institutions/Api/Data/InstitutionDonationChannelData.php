<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Data;

final readonly class InstitutionDonationChannelData
{
    public function __construct(
        public string $id,
        public string $label,
        public string $method,
        public string $method_display,
        public string $recipient,
        public string $payment_details,
        public ?string $bank_name,
        public ?string $bank_code,
        public ?string $account_number,
        public ?string $duitnow_type,
        public ?string $duitnow_value,
        public ?string $ewallet_provider,
        public ?string $ewallet_handle,
        public bool $is_default,
        public ?string $qr_url,
        public ?string $qr_full_url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
