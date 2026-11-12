<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaFileNamer;
use App\Shared\Contributions\Support\DonationQrUploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class DirectoryContributionMediaService implements DirectoryContributionMedia
{
    public function __construct(private MediaCollectionSync $mediaCollectionSync) {}

    public function donationChannelFingerprint(array $row): ?string
    {
        $method = is_string($row['method'] ?? null) ? trim($row['method']) : null;

        if (! in_array($method, ['bank_account', 'duitnow', 'ewallet'], true)) {
            return null;
        }

        $parts = match ($method) {
            'bank_account' => [$row['bank_code'] ?? null, $row['bank_name'] ?? null, $row['account_number'] ?? null],
            'duitnow' => [$row['duitnow_type'] ?? null, $row['duitnow_value'] ?? null],
            default => [$row['ewallet_provider'] ?? null, $row['ewallet_handle'] ?? null, $row['ewallet_qr_payload'] ?? null],
        };

        $normalized = array_map(
            static fn (mixed $value): string => is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : '',
            $parts,
        );

        if (array_filter($normalized) === []) {
            return null;
        }

        return 'sha256:'.hash('sha256', $method.'|'.implode('|', $normalized));
    }

    public function assertDonationQrUploadsFresh(mixed $donationRows): void
    {
        $rows = is_array($donationRows) ? $donationRows : [];
        $failed = [];

        foreach (DonationQrUploads::collect($rows) as $index => $file) {
            $row = $rows[$index] ?? null;
            $fingerprint = is_array($row) ? $this->donationChannelFingerprint($row) : null;

            try {
                $fresh = $file->exists();
            } catch (\Throwable) {
                $fresh = false;
            }

            if (! $fresh || $fingerprint === null) {
                $failed["data.donation_channels.{$index}.qr_upload"] = __('The QR upload expired or is invalid. Please upload the QR image again.');
            }
        }

        if ($failed !== []) {
            throw ValidationException::withMessages($failed);
        }
    }

    public function safeDonationQrFileName(TemporaryUploadedFile $file, ?HasMedia $subject = null): string
    {
        $extension = strtolower((string) $file->guessExtension());

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'png';
        }

        $base = MediaFileNamer::resolveBaseNameFromModel($subject instanceof Model ? $subject : null);
        $suffix = strtolower(substr((string) Str::ulid(), -8));

        return "{$base}-{$suffix}.{$extension}";
    }

    public function attachDonationQrUploads(HasMedia $model, array $submittedRows, array $uploads): void
    {
        if (! method_exists($model, 'donationChannels') || $uploads === []) {
            return;
        }

        $failed = [];

        foreach ($uploads as $index => $file) {
            $row = $submittedRows[$index] ?? null;
            $fingerprint = is_array($row) ? $this->donationChannelFingerprint($row) : null;
            $channel = $fingerprint === null ? null : $this->findDonationChannelByFingerprint($model, $fingerprint);

            try {
                $contents = $file->exists() ? $file->get() : null;
            } catch (\Throwable) {
                $contents = null;
            }

            if (! is_string($contents) || $contents === '' || ! $channel instanceof HasMedia) {
                $failed["data.donation_channels.{$index}.qr_upload"] = __('The QR upload expired or is invalid. Please upload the QR image again.');

                continue;
            }

            $channel->addMediaFromString($contents)
                ->usingFileName($this->safeDonationQrFileName($file, $channel))
                ->usingName(MediaFileNamer::resolveDisplayNameFromModel($channel, 'qr', $file->getClientOriginalName()))
                ->toMediaCollection('qr');
        }

        if ($failed !== []) {
            throw ValidationException::withMessages($failed);
        }
    }

    public function attachStagedDonationQr(HasMedia $entity, Media $staged): void
    {
        $fingerprint = $staged->getCustomProperty('donation_fingerprint');
        $channel = is_string($fingerprint) && $fingerprint !== '' && method_exists($entity, 'donationChannels')
            ? $this->findDonationChannelByFingerprint($entity, $fingerprint)
            : null;

        if ($channel instanceof HasMedia) {
            $channel->addMediaFromDisk($staged->getPathRelativeToRoot(), $staged->disk)
                ->usingFileName($staged->file_name)
                ->toMediaCollection('qr');
        }

        $this->mediaCollectionSync->deleteMedia($staged);
    }

    public function applyStagedMedia(HasMedia $entity, iterable $stagedMedia): void
    {
        $this->mediaCollectionSync->applyStagedMedia(
            $entity,
            $stagedMedia,
            fn (Media $media): bool => $this->applySpecialStagedMedia($entity, $media),
        );
    }

    private function applySpecialStagedMedia(HasMedia $entity, Media $media): bool
    {
        if ((string) $media->getCustomProperty('contribution_field', 'cover') !== 'donation_qr') {
            return false;
        }

        $this->attachStagedDonationQr($entity, $media);

        return true;
    }

    private function findDonationChannelByFingerprint(HasMedia $model, string $fingerprint): ?Model
    {
        if (! method_exists($model, 'donationChannels')) {
            return null;
        }

        return $model->donationChannels()->get()->first(
            fn (Model $channel): bool => $this->donationChannelFingerprint($this->donationChannelRow($channel)) === $fingerprint
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function donationChannelRow(Model $channel): array
    {
        return [
            'label' => $channel->getAttribute('label'),
            'recipient' => $channel->getAttribute('recipient'),
            'method' => $channel->getAttribute('method'),
            'bank_code' => $channel->getAttribute('bank_code'),
            'bank_name' => $channel->getAttribute('bank_name'),
            'account_number' => $channel->getAttribute('account_number'),
            'duitnow_type' => $channel->getAttribute('duitnow_type'),
            'duitnow_value' => $channel->getAttribute('duitnow_value'),
            'ewallet_provider' => $channel->getAttribute('ewallet_provider'),
            'ewallet_handle' => $channel->getAttribute('ewallet_handle'),
            'ewallet_qr_payload' => $channel->getAttribute('ewallet_qr_payload'),
            'reference_note' => $channel->getAttribute('reference_note'),
            'is_default' => (bool) $channel->getAttribute('is_default'),
        ];
    }
}
