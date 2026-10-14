<?php

declare(strict_types=1);

namespace Tests\Feature\ShareCards;

use App\Contracts\ShareCards\ShareCardReadiness;
use App\Contracts\ShareCards\ShareCardRenderer;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Contracts\ShareCards\ShareCardRenderResult;
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
