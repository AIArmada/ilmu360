<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Api\Data\DonationBank;
use App\Features\DonationChannels\Api\Data\DonationChannelCountryProfile;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationChannelOptions;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Normalization\DonationPayoutNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveDonationChannelAction
{
    use AsAction;

    public function __construct(
        private MediaCollectionSync $mediaSyncService,
        private TransitionDonationChannelStatusAction $transitionStatus,
        private DonationChannelCountryProfiles $countryProfiles,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?DonationChannel $donationChannel = null): DonationChannel
    {
        $creating = ! $donationChannel instanceof DonationChannel;

        if ($donationChannel instanceof DonationChannel) {
            MorphModelResolver::assert($donationChannel, 'donation_channel');
        }

        $donationChannel ??= new DonationChannel;

        $owner = $this->normalizeOwner(
            $data['donatable_type'] ?? $donationChannel->getAttribute('donatable_type'),
            $data['donatable_id'] ?? $donationChannel->getAttribute('donatable_id'),
        );

        $method = $this->normalizeMethod($data['method'] ?? $donationChannel->getAttribute('method') ?? ($creating ? 'bank_account' : null));
        $status = $this->normalizeStatus($data['status'] ?? $donationChannel->getAttribute('status') ?? ($creating ? DonationChannelStatus::Pending : null));
        $profile = $this->countryProfiles->forOwner($owner);

        $this->assertMethodAllowed($method, $profile);

        $methodAttributes = $this->methodAttributes($method, $data, $donationChannel, $profile);
        $destinationChanged = $this->destinationChanged($donationChannel, $method, $methodAttributes);

        if ($destinationChanged && $status === DonationChannelStatus::Verified) {
            $status = DonationChannelStatus::Pending;
        }

        $donationChannel->fill(array_merge([
            'donatable_type' => (string) $owner->getMorphClass(),
            'donatable_id' => (string) $owner->getKey(),
            'label' => array_key_exists('label', $data)
                ? $this->normalizeOptionalString($data['label'])
                : $donationChannel->getAttribute('label'),
            'recipient' => $this->normalizeRequiredString($data['recipient'] ?? $donationChannel->getAttribute('recipient'), 'recipient'),
            'method' => $method,
            'is_default' => array_key_exists('is_default', $data)
                ? (bool) $data['is_default']
                : ($creating ? false : (bool) $donationChannel->getAttribute('is_default')),
        ], $methodAttributes));

        DB::transaction(function () use ($data, $destinationChanged, $donationChannel, $status): void {
            if ($destinationChanged) {
                $donationChannel->setAttribute('verified_by', null);
                $donationChannel->setAttribute('verified_at', null);
            }

            $donationChannel->save();

            if ($donationChannel->getAttribute('status') !== $status) {
                $this->transitionStatus->handle($donationChannel, $status);
            }

            $qrUpload = $data['qr'] ?? null;

            if ($qrUpload instanceof UploadedFile) {
                $donationChannel->unsetRelation('media');
                $media = $this->mediaSyncService->storeSingle($donationChannel, $qrUpload, 'qr');
                $this->mediaSyncService->removeFilesOnRollback($media);
            } elseif (($data['clear_qr'] ?? false) === true || $destinationChanged) {
                $donationChannel->unsetRelation('media');
                $this->mediaSyncService->clearCollection($donationChannel, 'qr');
            }
        });

        return $donationChannel->fresh(['donatable', 'media', 'verifier']) ?? $donationChannel;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function methodAttributes(string $method, array $data, DonationChannel $donationChannel, DonationChannelCountryProfile $profile): array
    {
        return match ($method) {
            'bank_account' => $this->bankAccountAttributes($data, $donationChannel, $profile),
            'duitnow' => [
                'currency' => 'MYR',
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'swift_bic' => null,
                'routing_code' => null,
                'duitnow_type' => $this->normalizeRequiredOption($data['duitnow_type'] ?? $donationChannel->getAttribute('duitnow_type'), 'duitnow_type', DonationChannelOptions::DUITNOW_ID_TYPES),
                'duitnow_value' => $this->normalizeRequiredString($data['duitnow_value'] ?? $donationChannel->getAttribute('duitnow_value'), 'duitnow_value'),
                'ewallet_provider' => null,
                'ewallet_handle' => null,
                'ewallet_qr_payload' => null,
            ],
            'ewallet' => [
                'currency' => 'MYR',
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'iban' => null,
                'swift_bic' => null,
                'routing_code' => null,
                'duitnow_type' => null,
                'duitnow_value' => null,
                'ewallet_provider' => $this->normalizeRequiredOption($data['ewallet_provider'] ?? $donationChannel->getAttribute('ewallet_provider'), 'ewallet_provider', DonationChannelOptions::EWALLET_PROVIDERS),
                'ewallet_handle' => array_key_exists('ewallet_handle', $data)
                    ? $this->normalizeOptionalString($data['ewallet_handle'])
                    : $donationChannel->getAttribute('ewallet_handle'),
                'ewallet_qr_payload' => array_key_exists('ewallet_qr_payload', $data)
                    ? $this->normalizeOptionalString($data['ewallet_qr_payload'])
                    : $donationChannel->getAttribute('ewallet_qr_payload'),
            ],
            default => throw ValidationException::withMessages([
                'method' => __('The selected donation method is invalid.'),
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function bankAccountAttributes(array $data, DonationChannel $donationChannel, DonationChannelCountryProfile $profile): array
    {
        $accountNumber = array_key_exists('account_number', $data)
            ? $this->normalizeOptionalString($data['account_number'])
            : $this->normalizeOptionalString($donationChannel->getAttribute('account_number'));
        $iban = array_key_exists('iban', $data)
            ? $this->normalizeIban($data['iban'])
            : $this->normalizeIban($donationChannel->getAttribute('iban'));

        if ($accountNumber === null && $iban === null) {
            throw ValidationException::withMessages([
                'account_number' => __('Enter an account number or an IBAN.'),
            ]);
        }

        $bank = $this->resolveBank($data, $donationChannel, $profile);

        return [
            'currency' => $this->resolveCurrency($data, $donationChannel, $profile),
            'bank_code' => $bank->code,
            'bank_name' => $bank->name,
            'account_number' => $accountNumber,
            'iban' => $iban,
            'swift_bic' => array_key_exists('swift_bic', $data)
                ? $this->normalizeSwiftBic($data['swift_bic'])
                : $this->normalizeSwiftBic($donationChannel->getAttribute('swift_bic')),
            'routing_code' => $this->resolveRoutingCode($data, $donationChannel, $profile),
            'duitnow_type' => null,
            'duitnow_value' => null,
            'ewallet_provider' => null,
            'ewallet_handle' => null,
            'ewallet_qr_payload' => null,
        ];
    }

    /**
     * Resolve the bank from the owner country catalog and derive its display
     * name; caller supplied names may only confirm the catalog name.
     *
     * A channel that still submits the exact bank code it already stores keeps
     * its stored reference when the catalog entry was retired, so editing
     * account details never silently forces a payout change. Any other code
     * must come from the active catalog.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveBank(array $data, DonationChannel $donationChannel, DonationChannelCountryProfile $profile): DonationBank
    {
        if (! $profile->banks->hasEntries()) {
            throw ValidationException::withMessages([
                'bank_code' => __('Bank account donations are not available for this country.'),
            ]);
        }

        $bankCode = array_key_exists('bank_code', $data)
            ? $this->normalizeOptionalString($data['bank_code'])
            : $this->normalizeOptionalString($donationChannel->getAttribute('bank_code'));

        if ($bankCode === null) {
            throw ValidationException::withMessages([
                'bank_code' => __('Select the bank for this account.'),
            ]);
        }

        $bank = $profile->banks->find($bankCode) ?? $this->retainedBank($donationChannel, $bankCode, $profile);

        if (! $bank instanceof DonationBank) {
            throw ValidationException::withMessages([
                'bank_code' => __('The selected bank is not available for this country.'),
            ]);
        }

        $suppliedName = array_key_exists('bank_name', $data)
            ? $this->normalizeOptionalString($data['bank_name'])
            : null;

        if ($suppliedName !== null && mb_strtolower($suppliedName) !== mb_strtolower($bank->name)) {
            throw ValidationException::withMessages([
                'bank_name' => __('The bank name must match the selected bank.'),
            ]);
        }

        return $bank;
    }

    /**
     * Keep a stored payout resolvable when its catalog entry was retired.
     */
    private function retainedBank(DonationChannel $donationChannel, string $bankCode, DonationChannelCountryProfile $profile): ?DonationBank
    {
        if (! $donationChannel->exists) {
            return null;
        }

        $storedCode = $this->normalizeOptionalString($donationChannel->getAttribute('bank_code'));

        if ($storedCode === null || mb_strtoupper($storedCode) !== mb_strtoupper($bankCode)) {
            return null;
        }

        return $profile->banks->reference($bankCode);
    }

    /**
     * Resolve the optional local routing / sort code for bank accounts.
     *
     * Only countries whose trusted bank catalog defines routing codes accept
     * one; the code names the branch or local clearing system that routes the
     * transfer next to the account number.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveRoutingCode(array $data, DonationChannel $donationChannel, DonationChannelCountryProfile $profile): ?string
    {
        $routingCode = array_key_exists('routing_code', $data)
            ? DonationPayoutNormalizer::routingCode($data['routing_code'])
            : DonationPayoutNormalizer::routingCode($donationChannel->getAttribute('routing_code'));

        if ($routingCode === null) {
            return null;
        }

        if (! $profile->supports_routing_codes) {
            throw ValidationException::withMessages([
                'routing_code' => __('Routing / sort codes are not used for the banks of this country.'),
            ]);
        }

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/', $routingCode) !== 1) {
            throw ValidationException::withMessages([
                'routing_code' => __('The routing / sort code format is invalid.'),
            ]);
        }

        return $routingCode;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCurrency(array $data, DonationChannel $donationChannel, DonationChannelCountryProfile $profile): ?string
    {
        if (array_key_exists('currency', $data)) {
            $currency = DonationPayoutNormalizer::currency($data['currency']);

            if ($currency !== null) {
                return $this->assertSupportedCurrency($currency);
            }
        }

        $existing = DonationPayoutNormalizer::currency($donationChannel->getAttribute('currency'));

        return $existing ?? $profile->default_currency;
    }

    private function assertSupportedCurrency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages([
                'currency' => __('The currency must be a three-letter ISO code.'),
            ]);
        }

        if (! array_key_exists($currency, $this->countryProfiles->currencyOptions())) {
            throw ValidationException::withMessages([
                'currency' => __('The selected currency is not supported.'),
            ]);
        }

        return $currency;
    }

    private function normalizeIban(mixed $value): ?string
    {
        $iban = DonationPayoutNormalizer::iban($value);

        if ($iban === null) {
            return null;
        }

        if (DonationPayoutNormalizer::isIban($iban) && DonationPayoutNormalizer::hasValidIbanChecksum($iban)) {
            return $iban;
        }

        throw ValidationException::withMessages([
            'iban' => __('The IBAN format is invalid.'),
        ]);
    }

    private function normalizeSwiftBic(mixed $value): ?string
    {
        $swiftBic = DonationPayoutNormalizer::swiftBic($value);

        if ($swiftBic === null) {
            return null;
        }

        if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swiftBic) === 1) {
            return $swiftBic;
        }

        throw ValidationException::withMessages([
            'swift_bic' => __('The SWIFT / BIC format is invalid.'),
        ]);
    }

    private function assertMethodAllowed(string $method, DonationChannelCountryProfile $profile): void
    {
        if (in_array($method, ['duitnow', 'ewallet'], true) && ! $profile->isMalaysia()) {
            throw ValidationException::withMessages([
                'method' => __('DuitNow and e-wallet are only available for Malaysian institutions.'),
            ]);
        }
    }

    /**
     * @param  array<string, string|null>  $methodAttributes
     */
    private function destinationChanged(DonationChannel $donationChannel, string $method, array $methodAttributes): bool
    {
        if (! $donationChannel->exists) {
            return false;
        }

        $stored = DonationPayoutNormalizer::identityFingerprint([
            'method' => $donationChannel->getAttribute('method'),
            'bank_code' => $donationChannel->getAttribute('bank_code'),
            'bank_name' => $donationChannel->getAttribute('bank_name'),
            'account_number' => $donationChannel->getAttribute('account_number'),
            'iban' => $donationChannel->getAttribute('iban'),
            'swift_bic' => $donationChannel->getAttribute('swift_bic'),
            'routing_code' => $donationChannel->getAttribute('routing_code'),
            'duitnow_type' => $donationChannel->getAttribute('duitnow_type'),
            'duitnow_value' => $donationChannel->getAttribute('duitnow_value'),
            'ewallet_provider' => $donationChannel->getAttribute('ewallet_provider'),
            'ewallet_handle' => $donationChannel->getAttribute('ewallet_handle'),
            'ewallet_qr_payload' => $donationChannel->getAttribute('ewallet_qr_payload'),
        ]);

        $updated = DonationPayoutNormalizer::identityFingerprint([
            'method' => $method,
            ...$methodAttributes,
        ]);

        return $stored !== null && $updated !== null && $stored !== $updated;
    }

    private function normalizeOwner(mixed $type, mixed $id): Model
    {
        $modelClass = $this->ownerModelClass($type);
        $ownerId = $this->normalizeRequiredString($id, 'donatable_id');

        $owner = $modelClass::query()->find($ownerId);

        if (! $owner instanceof Model) {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        return $owner;
    }

    /**
     * @return class-string<Model>
     */
    private function ownerModelClass(mixed $value): string
    {
        $normalized = is_scalar($value) ? trim((string) $value) : '';

        if (in_array($normalized, ['institution', 'institutions'], true) || str_ends_with($normalized, '\\Institution')) {
            return MorphModelResolver::resolve('institution');
        }

        if (in_array($normalized, ['person', 'persons'], true) || str_ends_with($normalized, '\\Person')) {
            return MorphModelResolver::resolve('person');
        }

        if (in_array($normalized, ['event', 'events'], true) || str_ends_with($normalized, '\\Event')) {
            return MorphModelResolver::resolve('event');
        }

        if (in_array($normalized, ['organization', 'organizations'], true) || str_ends_with($normalized, '\\Organization')) {
            return MorphModelResolver::resolve('organization');
        }

        throw ValidationException::withMessages([
            'donatable_type' => __('The selected donation channel owner type is invalid.'),
        ]);
    }

    private function normalizeMethod(mixed $value): string
    {
        $method = $this->normalizeRequiredString($value, 'method');

        if (! in_array($method, ['bank_account', 'duitnow', 'ewallet'], true)) {
            throw ValidationException::withMessages([
                'method' => __('The selected donation method is invalid.'),
            ]);
        }

        return $method;
    }

    private function normalizeStatus(mixed $value): DonationChannelStatus
    {
        if ($value instanceof DonationChannelStatus) {
            return $value;
        }

        $status = DonationChannelStatus::tryFrom($this->normalizeRequiredString($value, 'status'));

        if (! $status instanceof DonationChannelStatus) {
            throw ValidationException::withMessages([
                'status' => __('The selected donation channel status is invalid.'),
            ]);
        }

        return $status;
    }

    /** @param array<string, string> $options */
    private function normalizeRequiredOption(mixed $value, string $field, array $options): string
    {
        $value = $this->normalizeRequiredString($value, $field);

        if (! array_key_exists($value, $options)) {
            throw ValidationException::withMessages([
                $field => __('validation.in', ['attribute' => str_replace('_', ' ', $field)]),
            ]);
        }

        return $value;
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => __('This field is required.'),
            ]);
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
