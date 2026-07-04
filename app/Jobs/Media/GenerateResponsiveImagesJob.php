<?php

declare(strict_types=1);

namespace App\Jobs\Media;

class GenerateResponsiveImagesJob extends \Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob
{
    public bool $deleteWhenMissingModels = true;
}
