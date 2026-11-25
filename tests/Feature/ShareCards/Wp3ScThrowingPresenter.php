<?php

declare(strict_types=1);

namespace Tests\Feature\ShareCards;

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use App\Features\Events\Models\Event;
use App\Features\Events\Services\ShareCards\ShareCardEventPresenter;
use App\Shared\Media\ShareCards\ShareCardRenderInput;
use RuntimeException;

final readonly class Wp3ScThrowingPresenter extends ShareCardEventPresenter
{
    public function toRenderInput(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): ShareCardRenderInput
    {
        throw new RuntimeException('wp3 card presenter failure probe');
    }
}
