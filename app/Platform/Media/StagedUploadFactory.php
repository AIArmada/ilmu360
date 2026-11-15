<?php

declare(strict_types=1);

namespace App\Platform\Media;

use App\Shared\Media\StagedUpload;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class StagedUploadFactory
{
    public function fromUploadedFile(UploadedFile $file): StagedUpload
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('The uploaded file is invalid.');
        }

        $path = $file->getRealPath();
        $mimeType = $file->getMimeType();
        $sizeBytes = $file->getSize();

        if (! is_string($path) || ! is_file($path) || ! is_readable($path)
            || ! is_string($mimeType) || ! is_int($sizeBytes)) {
            throw new InvalidArgumentException('The uploaded file could not be staged.');
        }

        return new StagedUpload(
            path: $path,
            originalFileName: $file->getClientOriginalName(),
            mimeType: $mimeType,
            sizeBytes: $sizeBytes,
        );
    }

    public function toUploadedFile(StagedUpload $upload): UploadedFile
    {
        if (! is_file($upload->path) || ! is_readable($upload->path)) {
            throw new InvalidArgumentException('The staged upload is no longer available.');
        }

        $file = new UploadedFile(
            $upload->path,
            $upload->originalFileName,
            $upload->mimeType,
            UPLOAD_ERR_OK,
            true,
        );

        if ($file->getSize() !== $upload->sizeBytes) {
            throw new InvalidArgumentException('The staged upload changed after validation.');
        }

        return $file;
    }
}
