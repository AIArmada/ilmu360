<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Api\Data\DonationChannelCountryProfile;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Normalization\DonationPayoutNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconcile the institution's donation channels with a submitted row set.
 *
 * The country policy is resolved from the owner's canonical primary address,
 * never from the caller, and the final channel set is validated atomically so
 * a country change cannot leave incompatible channels behind.
 */
final readonly class SyncInstitutionDonationChannelsAction
{
    public function __construct(
        private SaveDonationChannelAction $saveDonationChannel,
        private DonationChannelCountryProfiles $countryProfiles,
    ) {}

    public function handle(string $institutionId, mixed $donationChannelsPayload): void
    {
        $institution = $this->institution($institutionId);
        $profile = $this->countryProfiles->forOwner($institution);

        $existing = DonationChannel::query()
            ->where('donatable_type', 'institution')
            ->where('donatable_id', $institutionId)
            ->get();

        if ($donationChannelsPayload === null) {
            $this->assertExistingChannelsAllowedForProfile(
                $existing,
                $profile,
                __('Remove the donation channels that the institution\'s country does not support before changing the country.'),
            );

            return;
        }

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

        $this->assertSubmittedChannelsAllowedForProfile($rows, $existing, $profile);

        DB::transaction(function () use ($rows, $existing, $institutionId): void {
            $matchedIds = [];

            foreach ($rows as $row) {
                $fingerprint = DonationPayoutNormalizer::identityFingerprint($row);
                $match = $existing->first(fn (Model $channel): bool => ! in_array($channel->getKey(), $matchedIds, true)
                    && DonationPayoutNormalizer::identityFingerprint($this->storedRow($channel)) === $fingerprint);

                if (! $match instanceof Model) {
                    $created = $this->saveDonationChannel->handle(array_merge($row, [
                        'donatable_type' => 'institution',
                        'donatable_id' => $institutionId,
                    ]));
                    $matchedIds[] = $created->getKey();

                    continue;
                }

                if ($row['currency'] === null) {
                    unset($row['currency']);
                }

                $this->saveDonationChannel->handle($row, $match);
                $matchedIds[] = $match->getKey();
            }

            $this->deleteUnmatchedChannels($institutionId, $matchedIds);
        });
    }

    /**
     * Validate the owner's stored channels against the resolved profile.
     *
     * Retired catalog entries still count: a stored reference stays valid for
     * the same country so an edit never forces a payout change, while a
     * country change still fails because the new country's catalog does not
     * hold the code.
     *
     * @param  Collection<int, DonationChannel>  $existing
     */
    private function assertExistingChannelsAllowedForProfile(Collection $existing, DonationChannelCountryProfile $profile, string $message): void
    {
        foreach ($existing as $channel) {
            $method = is_string($channel->getAttribute('method')) ? mb_strtolower((string) $channel->getAttribute('method')) : null;
            $bankCode = $channel->getAttribute('bank_code');

            $bankAllowed = $method !== 'bank_account'
                || (is_string($bankCode) && $profile->banks->retains($bankCode));

            if ($method === null || ! $profile->supports($method) || ! $bankAllowed) {
                throw ValidationException::withMessages([
                    'data.donation_channels' => $message,
                ]);
            }
        }
    }

    /**
     * Validate submitted rows against the resolved profile.
     *
     * A row that still matches one of the owner's stored payout identities may
     * keep a retired catalog reference; every other row must select an active
     * bank of the resolved country.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  Collection<int, DonationChannel>  $existing
     */
    private function assertSubmittedChannelsAllowedForProfile(iterable $rows, Collection $existing, DonationChannelCountryProfile $profile): void
    {
        $retained = [];

        foreach ($existing as $channel) {
            $fingerprint = DonationPayoutNormalizer::identityFingerprint($this->storedRow($channel));

            if ($fingerprint !== null) {
                $retained[] = $fingerprint;
            }
        }

        foreach ($rows as $row) {
            $method = is_string($row['method'] ?? null) ? mb_strtolower($row['method']) : null;
            $bankCode = is_string($row['bank_code'] ?? null) ? $row['bank_code'] : null;
            $fingerprint = DonationPayoutNormalizer::identityFingerprint($row);
            $retainedReference = is_string($fingerprint) && in_array($fingerprint, $retained, true);

            $bankAllowed = $method !== 'bank_account'
                || ($bankCode !== null && ($profile->banks->has($bankCode)
                    || ($retainedReference && $profile->banks->retains($bankCode))));

            if ($method === null || ! $profile->supports($method) || ! $bankAllowed) {
                throw ValidationException::withMessages([
                    'data.donation_channels' => __('One or more donation channels are not available for the institution\'s country.'),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $matchedIds
     */
    private function deleteUnmatchedChannels(string $institutionId, array $matchedIds): void
    {
        // Delete through the models so Spatie media cleanup and model audits
        // run for removed channels instead of orphaning their QR media.
        $query = DonationChannel::query()
            ->where('donatable_type', 'institution')
            ->where('donatable_id', $institutionId);

        if ($matchedIds !== []) {
            $query->whereKeyNot($matchedIds);
        }

        $query->get()->each->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $canonical = DonationPayoutNormalizer::canonicalRow($row);

        if (! in_array($canonical['method'], ['bank_account', 'duitnow', 'ewallet'], true) || $canonical['recipient'] === null) {
            return null;
        }

        $accountPresent = match ($canonical['method']) {
            'bank_account' => $canonical['account_number'] !== null || $canonical['iban'] !== null,
            'duitnow' => $canonical['duitnow_value'] !== null,
            default => $canonical['ewallet_provider'] !== null
                && ($canonical['ewallet_handle'] !== null || $canonical['ewallet_qr_payload'] !== null),
        };

        return $accountPresent ? $canonical : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(Model $channel): array
    {
        return [
            'method' => $channel->getAttribute('method'),
            'bank_code' => $channel->getAttribute('bank_code'),
            'bank_name' => $channel->getAttribute('bank_name'),
            'account_number' => $channel->getAttribute('account_number'),
            'iban' => $channel->getAttribute('iban'),
            'swift_bic' => $channel->getAttribute('swift_bic'),
            'routing_code' => $channel->getAttribute('routing_code'),
            'duitnow_type' => $channel->getAttribute('duitnow_type'),
            'duitnow_value' => $channel->getAttribute('duitnow_value'),
            'ewallet_provider' => $channel->getAttribute('ewallet_provider'),
            'ewallet_handle' => $channel->getAttribute('ewallet_handle'),
            'ewallet_qr_payload' => $channel->getAttribute('ewallet_qr_payload'),
        ];
    }

    private function institution(string $institutionId): Model
    {
        $modelClass = MorphModelResolver::resolve('institution');

        $institution = $modelClass::query()->find($institutionId);

        if (! $institution instanceof Model) {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        return $institution;
    }
}
