<?php

namespace App\Jobs\Media;

class GenerateResponsiveImagesJob extends \Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob
{
    public bool $deleteWhenMissingModels = true;
}
