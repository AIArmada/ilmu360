<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Actions\DonationChannels\SaveDonationChannelAction;
use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Reconcile the institution's donation channels with a submitted row set.
 */
final readonly class SyncInstitutionDonationChannelsAction
{
    public function __construct(private SaveDonationChannelAction $saveDonationChannel) {}

    public function handle(Model $institution, mixed $donationChannelsPayload): void
    {
        $submittedRows = collect(is_array($donationChannelsPayload) ? $donationChannelsPayload : [])
            ->values();

        $rows = $submittedRows
            ->filter(fn (mixed $value): bool => is_array($value))
            ->values()
            ->map(fn (array $row): ?array => $this->normalizeRow($row))
            ->filter()
            ->values();

        // Every submitted row must normalize: a partially filled row is a
        // caller error, not an empty row, and dropping it silently would lose
        // data the contributor believes was saved.
        if ($submittedRows->count() !== $rows->count()) {
            throw ValidationException::withMessages([
                'data.donation_channels' => __('Every donation channel must be complete before submitting.'),
            ]);
        }

        $institutionId = (string) $institution->getKey();
        $existing = DirectoryModel::query('donation_channel')
            ->where('donatable_type', $institution->getMorphClass())
            ->where('donatable_id', $institutionId)
            ->get();
        $matchedIds = [];

        foreach ($rows as $row) {
            $match = $existing->first(fn (Model $channel): bool => ! in_array($channel->getKey(), $matchedIds, true)
                && $this->sameIdentity($channel, $row));

            if (! $match instanceof Model) {
                $created = $this->saveDonationChannel->handle(array_merge($row, [
                    'donatable_type' => $institution->getMorphClass(),
                    'donatable_id' => $institutionId,
                ]));
                $matchedIds[] = $created->getKey();

                continue;
            }

            $this->saveDonationChannel->handle($row, $match);
            $matchedIds[] = $match->getKey();
        }

        // Delete through the models so Spatie media cleanup and model audits
        // run for removed channels instead of orphaning their QR media.
        if ($matchedIds === []) {
            DirectoryModel::query('donation_channel')
                ->where('donatable_type', $institution->getMorphClass())
                ->where('donatable_id', $institutionId)
                ->get()
                ->each->delete();

            return;
        }

        DirectoryModel::query('donation_channel')
            ->where('donatable_type', $institution->getMorphClass())
            ->where('donatable_id', $institutionId)
            ->whereKeyNot($matchedIds)
            ->get()
            ->each->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $method = is_string($row['method'] ?? null) ? trim($row['method']) : null;
        $recipient = is_string($row['recipient'] ?? null) ? trim($row['recipient']) : null;

        if (! in_array($method, ['bank_account', 'duitnow', 'ewallet'], true) || $recipient === null || $recipient === '') {
            return null;
        }

        $accountPresent = match ($method) {
            'bank_account' => $this->normalizedNullableString($row['account_number'] ?? null) !== null,
            'duitnow' => $this->normalizedNullableString($row['duitnow_value'] ?? null) !== null,
            default => $this->normalizedNullableString($row['ewallet_provider'] ?? null) !== null
                && ($this->normalizedNullableString($row['ewallet_handle'] ?? null) !== null
                    || $this->normalizedNullableString($row['ewallet_qr_payload'] ?? null) !== null),
        };

        if (! $accountPresent) {
            return null;
        }

        return [
            'label' => $this->normalizedNullableString($row['label'] ?? null),
            'recipient' => $recipient,
            'method' => $method,
            'bank_code' => $this->normalizedNullableString($row['bank_code'] ?? null),
            'bank_name' => $this->normalizedNullableString($row['bank_name'] ?? null),
            'account_number' => $this->normalizedNullableString($row['account_number'] ?? null),
            'duitnow_type' => $this->normalizedNullableString($row['duitnow_type'] ?? null),
            'duitnow_value' => $this->normalizedNullableString($row['duitnow_value'] ?? null),
            'ewallet_provider' => $this->normalizedNullableString($row['ewallet_provider'] ?? null),
            'ewallet_handle' => $this->normalizedNullableString($row['ewallet_handle'] ?? null),
            'ewallet_qr_payload' => $this->normalizedNullableString($row['ewallet_qr_payload'] ?? null),
            'reference_note' => $this->normalizedNullableString($row['reference_note'] ?? null),
            'is_default' => (bool) ($row['is_default'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sameIdentity(Model $channel, array $row): bool
    {
        if ($channel->getAttribute('method') !== ($row['method'] ?? null)) {
            return false;
        }

        return match ($channel->getAttribute('method')) {
            'bank_account' => $this->sameIdentityPart($channel->getAttribute('bank_code'), $row['bank_code'] ?? null)
                && $this->sameIdentityPart($channel->getAttribute('bank_name'), $row['bank_name'] ?? null)
                && $this->sameIdentityPart($channel->getAttribute('account_number'), $row['account_number'] ?? null),
            'duitnow' => $this->sameIdentityPart($channel->getAttribute('duitnow_type'), $row['duitnow_type'] ?? null)
                && $this->sameIdentityPart($channel->getAttribute('duitnow_value'), $row['duitnow_value'] ?? null),
            'ewallet' => $this->sameIdentityPart($channel->getAttribute('ewallet_provider'), $row['ewallet_provider'] ?? null)
                && $this->sameIdentityPart($channel->getAttribute('ewallet_handle'), $row['ewallet_handle'] ?? null)
                && $this->sameIdentityPart($channel->getAttribute('ewallet_qr_payload'), $row['ewallet_qr_payload'] ?? null),
            default => false,
        };
    }

    private function sameIdentityPart(mixed $left, mixed $right): bool
    {
        $normalize = static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
            ? mb_strtolower(trim($value))
            : null;

        return $normalize($left) === $normalize($right);
    }

    private function normalizedNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
