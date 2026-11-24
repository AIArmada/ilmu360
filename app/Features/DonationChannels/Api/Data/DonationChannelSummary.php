<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Data;

use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;

/**
 * Scalar snapshot of one donation channel for owner workspaces and public
 * donor surfaces. Credential fields are the decrypted values the owning
 * feature already exposes to its readers.
 */
final readonly class DonationChannelSummary
{
    public function __construct(
        public string $id,
        public ?string $label,
        public string $method,
        public string $method_display,
        public string $recipient,
        public string $payment_details,
        public ?string $bank_code,
        public ?string $bank_name,
        public ?string $account_number,
        public ?string $iban,
        public ?string $swift_bic,
        public ?string $routing_code,
        public ?string $currency,
        public ?string $duitnow_type,
        public ?string $duitnow_value,
        public ?string $ewallet_provider,
        public ?string $ewallet_handle,
        public bool $is_default,
        public DonationChannelStatus $status,
        public ?string $qr_url,
        public ?string $qr_full_url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'method' => $this->method,
            'method_display' => $this->method_display,
            'recipient' => $this->recipient,
            'payment_details' => $this->payment_details,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'swift_bic' => $this->swift_bic,
            'routing_code' => $this->routing_code,
            'currency' => $this->currency,
            'duitnow_type' => $this->duitnow_type,
            'duitnow_value' => $this->duitnow_value,
            'ewallet_provider' => $this->ewallet_provider,
            'ewallet_handle' => $this->ewallet_handle,
            'is_default' => $this->is_default,
            'status' => $this->status->value,
            'qr_url' => $this->qr_url,
            'qr_full_url' => $this->qr_full_url,
        ];
    }
}
