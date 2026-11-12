<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Directory-owned contribution media writes for institutions, persons and
 * references: donation-channel QR uploads and media staged on a pending
 * contribution request.
 *
 * Media itself stays package-shared and unclaimed; this boundary only owns
 * how Directory subjects and their donation channels receive files.
 */
interface DirectoryContributionMedia
{
    /**
     * Stable identity for matching a submitted donation row to its persisted
     * channel. The identity is hashed so staged fingerprints never embed
     * account numbers in plaintext.
     *
     * @param  array<string, mixed>  $row
     */
    public function donationChannelFingerprint(array $row): ?string;

    /**
     * Verify every submitted donation QR upload is still readable before
     * anything is persisted, so an expired temporary file fails cleanly
     * instead of crashing form validation or stranding a half-built request.
     */
    public function assertDonationQrUploadsFresh(mixed $donationRows): void;

    /**
     * Build a storage filename for a donation QR upload following the media
     * naming policy, with the extension sniffed from the file contents
     * instead of trusting the client-provided name.
     */
    public function safeDonationQrFileName(TemporaryUploadedFile $file, ?HasMedia $subject = null): string;

    /**
     * Attach freshly uploaded donation QR images to their channels. Uploads
     * are keyed by submitted row so each file follows its row's identity,
     * even when invalid sibling rows were dropped during sync.
     *
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @param  array<int|string, TemporaryUploadedFile>  $uploads
     */
    public function attachDonationQrUploads(HasMedia $model, array $submittedRows, array $uploads): void;

    /**
     * Move a staged visitor QR onto its approved channel. The staged copy is
     * always discarded so approvals never leak pending media.
     */
    public function attachStagedDonationQr(HasMedia $entity, Media $staged): void;

    /**
     * Apply media staged on a pending contribution request to a Directory
     * subject: replace single-file collections, append multi-file
     * collections, and discard the staged copies.
     *
     * @param  iterable<int, Media>  $stagedMedia
     */
    public function applyStagedMedia(HasMedia $entity, iterable $stagedMedia): void;
}
