<?php

declare(strict_types=1);

namespace App\Actions\DonationChannels;

use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Support\Media\ModelMediaSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveDonationChannelAction
{
    use AsAction;

    public function __construct(
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?DonationChannel $donationChannel = null): DonationChannel
    {
        $creating = ! $donationChannel instanceof DonationChannel;
        $donationChannel ??= new DonationChannel;

        $owner = $this->normalizeOwner(
            $data['donatable_type'] ?? $donationChannel->donatable_type,
            $data['donatable_id'] ?? $donationChannel->donatable_id,
        );

        $method = $this->normalizeMethod($data['method'] ?? $donationChannel->method ?? ($creating ? 'bank_account' : null));

        $donationChannel->fill(array_merge([
            'donatable_type' => $owner['type'],
            'donatable_id' => $owner['id'],
            'label' => array_key_exists('label', $data)
                ? $this->normalizeOptionalString($data['label'])
                : $donationChannel->label,
            'recipient' => $this->normalizeRequiredString($data['recipient'] ?? $donationChannel->recipient, 'recipient'),
            'method' => $method,
            'reference_note' => array_key_exists('reference_note', $data)
                ? $this->normalizeOptionalString($data['reference_note'])
                : $donationChannel->reference_note,
            'status' => $this->normalizeStatus($data['status'] ?? $donationChannel->status ?? ($creating ? 'unverified' : null)),
            'is_default' => array_key_exists('is_default', $data)
                ? (bool) $data['is_default']
                : ($creating ? false : (bool) $donationChannel->is_default),
        ], $this->methodAttributes($method, $data, $donationChannel)));

        $donationChannel->save();

        if (($data['clear_qr'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($donationChannel, 'qr');
        }

        $this->mediaSyncService->syncSingle(
            $donationChannel,
            ($data['qr'] ?? null) instanceof UploadedFile ? $data['qr'] : null,
            'qr',
        );

        return $donationChannel->fresh(['donatable', 'media', 'verifier']) ?? $donationChannel;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function methodAttributes(string $method, array $data, DonationChannel $donationChannel): array
    {
        return match ($method) {
            'bank_account' => [
                'bank_code' => array_key_exists('bank_code', $data)
                    ? $this->normalizeOptionalString($data['bank_code'])
                    : $donationChannel->bank_code,
                'bank_name' => $this->normalizeRequiredString($data['bank_name'] ?? $donationChannel->bank_name, 'bank_name'),
                'account_number' => $this->normalizeRequiredString($data['account_number'] ?? $donationChannel->account_number, 'account_number'),
                'duitnow_type' => null,
                'duitnow_value' => null,
                'ewallet_provider' => null,
                'ewallet_handle' => null,
                'ewallet_qr_payload' => null,
            ],
            'duitnow' => [
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'duitnow_type' => $this->normalizeRequiredString($data['duitnow_type'] ?? $donationChannel->duitnow_type, 'duitnow_type'),
                'duitnow_value' => $this->normalizeRequiredString($data['duitnow_value'] ?? $donationChannel->duitnow_value, 'duitnow_value'),
                'ewallet_provider' => null,
                'ewallet_handle' => null,
                'ewallet_qr_payload' => null,
            ],
            'ewallet' => [
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'duitnow_type' => null,
                'duitnow_value' => null,
                'ewallet_provider' => $this->normalizeRequiredString($data['ewallet_provider'] ?? $donationChannel->ewallet_provider, 'ewallet_provider'),
                'ewallet_handle' => array_key_exists('ewallet_handle', $data)
                    ? $this->normalizeOptionalString($data['ewallet_handle'])
                    : $donationChannel->ewallet_handle,
                'ewallet_qr_payload' => array_key_exists('ewallet_qr_payload', $data)
                    ? $this->normalizeOptionalString($data['ewallet_qr_payload'])
                    : $donationChannel->ewallet_qr_payload,
            ],
            default => throw ValidationException::withMessages([
                'method' => __('The selected donation method is invalid.'),
            ]),
        };
    }

    /**
     * @return array{type: string, id: string}
     */
    private function normalizeOwner(mixed $type, mixed $id): array
    {
        $modelClass = $this->ownerModelClass($type);
        $ownerId = $this->normalizeRequiredString($id, 'donatable_id');

        $owner = $modelClass::query()->find($ownerId);

        if (! $owner instanceof Model) {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        return [
            'type' => (string) $owner->getMorphClass(),
            'id' => (string) $owner->getKey(),
        ];
    }

    /**
     * @return class-string<Model>
     */
    private function ownerModelClass(mixed $value): string
    {
        $normalized = is_scalar($value) ? trim((string) $value) : '';

        return match ($normalized) {
            'institution', 'institutions', Institution::class => Institution::class,
            'speaker', 'speakers', Speaker::class => Speaker::class,
            'event', 'events', Event::class => Event::class,
            default => throw ValidationException::withMessages([
                'donatable_type' => __('The selected donation channel owner type is invalid.'),
            ]),
        };
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

    private function normalizeStatus(mixed $value): string
    {
        $status = $this->normalizeRequiredString($value, 'status');

        if (! in_array($status, ['unverified', 'verified', 'rejected', 'inactive'], true)) {
            throw ValidationException::withMessages([
                'status' => __('The selected donation channel status is invalid.'),
            ]);
        }

        return $status;
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
