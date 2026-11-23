<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Services;

use App\Features\DonationChannels\Api\Data\DonationQrUploadData;
use App\Features\DonationChannels\Api\DonationQrWorkflow;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaFileNamer;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class DonationQrMediaService implements DonationQrWorkflow
{
    public function __construct(private MediaCollectionSync $mediaCollectionSync) {}

    /** @param array<string, mixed> $row */
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

    public function safeDonationQrFileName(string $guessedExtension, string $baseName = 'media'): string
    {
        $extension = strtolower($guessedExtension);

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'png';
        }

        return MediaFileNamer::generateStorageFileName(null, $extension, $baseName);
    }

    /** @param array<int|string, array<string, mixed>> $submittedRows
     * @param  array<int|string, DonationQrUploadData>  $uploads
     */
    public function attachDonationQrUploads(string $subjectType, string $subjectId, array $submittedRows, array $uploads): void
    {
        if ($uploads === []) {
            return;
        }

        $model = $this->subject($subjectType, $subjectId);

        if (! method_exists($model, 'donationChannels')) {
            return;
        }

        $failed = [];

        foreach ($uploads as $index => $file) {
            $row = $submittedRows[$index] ?? null;
            $fingerprint = is_array($row) ? $this->donationChannelFingerprint($row) : null;
            $channel = $fingerprint === null ? null : $this->findDonationChannelByFingerprint($model, $fingerprint);

            if (! $file instanceof DonationQrUploadData || ! is_string($file->contents) || $file->contents === '' || ! $channel instanceof HasMedia) {
                $failed["data.donation_channels.{$index}.qr_upload"] = __('The QR upload expired or is invalid. Please upload the QR image again.');

                continue;
            }

            $media = $channel->addMediaFromString($file->contents)
                ->usingFileName($this->safeDonationQrFileName($file->guessedExtension, MediaFileNamer::resolveBaseNameFromModel($channel)))
                ->usingName(MediaFileNamer::resolveDisplayNameFromModel($channel, 'qr', $file->originalFileName))
                ->toMediaCollection('qr');

            $this->mediaCollectionSync->removeFilesOnRollback($media);
        }

        if ($failed !== []) {
            throw ValidationException::withMessages($failed);
        }
    }

    public function attachStagedDonationQr(string $subjectType, string $subjectId, string $stagedMediaId): void
    {
        $entity = $this->subject($subjectType, $subjectId);
        $staged = Media::query()->findOrFail($stagedMediaId);
        $fingerprint = $staged->getCustomProperty('donation_fingerprint');
        $channel = is_string($fingerprint) && $fingerprint !== '' && method_exists($entity, 'donationChannels')
            ? $this->findDonationChannelByFingerprint($entity, $fingerprint)
            : null;

        if ($channel instanceof HasMedia) {
            $originalFileName = is_string($staged->getCustomProperty('original_file_name'))
                ? $staged->getCustomProperty('original_file_name')
                : $staged->file_name;

            $media = $channel->addMediaFromDisk($staged->getPathRelativeToRoot(), $staged->disk)
                ->preservingOriginal()
                ->usingFileName($this->safeDonationQrFileName(
                    pathinfo($staged->file_name, PATHINFO_EXTENSION),
                    MediaFileNamer::resolveBaseNameFromModel($channel),
                ))
                ->usingName(MediaFileNamer::resolveDisplayNameFromModel($channel, 'qr', $originalFileName))
                ->withCustomProperties([
                    'collection' => 'qr',
                    'original_file_name' => $originalFileName,
                ])
                ->toMediaCollection('qr');

            $this->mediaCollectionSync->removeFilesOnRollback($media);
        }

        $this->mediaCollectionSync->deleteMedia($staged);
    }

    private function subject(string $subjectType, string $subjectId): Model&HasMedia
    {
        $modelClass = MorphModelResolver::resolve($subjectType);
        $model = $modelClass::query()->findOrFail($subjectId);

        if (! $model instanceof HasMedia) {
            throw new \InvalidArgumentException('The donation QR target must support media collections.');
        }

        return $model;
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

    /** @return array<string, mixed> */
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
