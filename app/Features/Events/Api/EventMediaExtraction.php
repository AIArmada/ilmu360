<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventMediaExtraction
{
    public function extract(EventMediaExtractionSource $source): EventMediaExtractionResult;
}
