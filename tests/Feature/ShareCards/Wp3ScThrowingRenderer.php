<?php

declare(strict_types=1);

namespace Tests\Feature\ShareCards;

use App\Shared\Media\ShareCards\ShareCardReadiness;
use App\Shared\Media\ShareCards\ShareCardRenderer;
use App\Shared\Media\ShareCards\ShareCardRenderInput;
use App\Shared\Media\ShareCards\ShareCardRenderResult;
use RuntimeException;

final class Wp3ScThrowingRenderer implements ShareCardRenderer
{
    public function readiness(): ShareCardReadiness
    {
        throw new RuntimeException('wp3 card renderer failure probe');
    }

    public function render(ShareCardRenderInput $input): ShareCardRenderResult
    {
        throw new RuntimeException('wp3 card renderer failure probe');
    }
}
