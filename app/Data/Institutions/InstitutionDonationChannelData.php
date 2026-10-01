<?php

declare(strict_types=1);

namespace App\Data\Institutions;

use App\Data\InstitutionData;
use App\Enums\DonationChannelStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;

class InstitutionDonationChannelData extends Data
{
    /**
     * @var list<string>
     */
    public const array METHODS = ['bank_account', 'duitnow', 'ewallet'];

    /**
     * DuitNow identifier vocabulary, per the donation_channels schema.
     *
     * @var list<string>
     */
    public const array DUITNOW_TYPES = ['mobile', 'nric', 'business', 'passport'];

    public function __construct(
        public string $method,
        public string $recipient,
        public ?string $label = null,
        public ?string $bank_code = null,
        public ?string $bank_name = null,
        public ?string $account_number = null,
        public ?string $duitnow_type = null,
        public ?string $duitnow_value = null,
        public ?string $ewallet_provider = null,
        public ?string $ewallet_handle = null,
        public ?string $ewallet_qr_payload = null,
        public ?string $reference_note = null,
        public bool $is_default = false,
        public DonationChannelStatus $status = DonationChannelStatus::Pending,
    ) {}

    /**
     * Declarative rules pin the method vocabulary, identity, flags, and
     * status enum. Per-method required fields and the duitnow_type
     * vocabulary are enforced by assertMethodPayload() at the same
     * validateAndCreate boundary, mirroring SaveDonationChannelAction,
     * the Filament donation form, and the admin API conventions — never
     * after graph writes have started.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'method' => ['required', Rule::in(self::METHODS)],
            'recipient' => ['required', 'string', 'max:255', InstitutionData::nonBlankRule()],
            'label' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'duitnow_type' => ['nullable', 'string', 'max:50'],
            'duitnow_value' => ['nullable', 'string', 'max:255'],
            'ewallet_provider' => ['nullable', 'string', 'max:100'],
            'ewallet_handle' => ['nullable', 'string', 'max:255'],
            'ewallet_qr_payload' => ['nullable', 'string', 'max:2048'],
            'reference_note' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'status' => ['required', Rule::enum(DonationChannelStatus::class)],
        ];
    }

    /**
     * Runs when this Data is the validation root; the full-graph boundary
     * instead delegates here per channel payload from its own hook.
     */
    #[\Override]
    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            if (is_array($data)) {
                self::assertMethodPayload($data, $validator, '');
            }
        });
    }

    /**
     * Reject malformed per-method payloads: missing or blank required
     * fields, and duitnow_type values outside the schema vocabulary.
     * Blank strings fail without mutating the stored bytes.
     *
     * @param  array<string, mixed>  $item
     */
    public static function assertMethodPayload(array $item, Validator $validator, string $prefix): void
    {
        $method = $item['method'] ?? null;

        if (! is_string($method)) {
            return;
        }

        $required = match ($method) {
            'bank_account' => ['bank_name', 'account_number'],
            'duitnow' => ['duitnow_type', 'duitnow_value'],
            'ewallet' => ['ewallet_provider'],
            default => [],
        };

        foreach ($required as $field) {
            $value = $item[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $validator->errors()->add($prefix.$field, "The {$field} field is required for {$method} channels.");
            }
        }

        $duitnowType = $item['duitnow_type'] ?? null;

        if ($method === 'duitnow' && is_string($duitnowType) && trim($duitnowType) !== '' && ! in_array($duitnowType, self::DUITNOW_TYPES, true)) {
            $validator->errors()->add($prefix.'duitnow_type', 'The selected duitnow type is invalid.');
        }
    }
}
