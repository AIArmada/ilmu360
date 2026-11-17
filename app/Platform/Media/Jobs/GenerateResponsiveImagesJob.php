<?php

declare(strict_types=1);

namespace App\Platform\Media\Jobs;

class GenerateResponsiveImagesJob extends \Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob
{
    public bool $deleteWhenMissingModels = true;
}
