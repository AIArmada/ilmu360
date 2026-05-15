<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\DonationChannel;
use Spatie\LaravelData\Data;

class InstitutionDonationChannelData extends Data
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

    public static function fromModel(DonationChannel $channel): self
    {
        return new self(
            id: (string) $channel->id,
            label: (string) $channel->label,
            method: (string) $channel->method,
            method_display: (string) $channel->method_display,
            recipient: (string) $channel->recipient,
            payment_details: (string) $channel->payment_details,
            bank_name: $channel->bank_name,
            bank_code: $channel->bank_code,
            account_number: $channel->account_number,
            duitnow_type: $channel->duitnow_type,
            duitnow_value: $channel->duitnow_value,
            ewallet_provider: $channel->ewallet_provider,
            ewallet_handle: $channel->ewallet_handle,
            is_default: (bool) $channel->is_default,
            qr_url: $channel->getFirstMediaUrl('qr', 'thumb') ?: $channel->getFirstMediaUrl('qr') ?: null,
            qr_full_url: $channel->getFirstMediaUrl('qr') ?: null,
        );
    }
}
