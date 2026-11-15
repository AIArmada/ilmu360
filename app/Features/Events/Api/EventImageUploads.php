<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventImageUploads
{
    public function upload(UploadEventImageInput $input): EventImageUploadResult;
}
