<?php

declare(strict_types=1);

namespace Tests\Feature\ShareCards;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Models\Event;
use App\Shared\Media\ShareCards\ShareCardRenderInput;
use App\Features\Events\Services\ShareCards\ShareCardEventPresenter;
use RuntimeException;

final readonly class Wp3ScThrowingPresenter extends ShareCardEventPresenter
{
    public function toRenderInput(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): ShareCardRenderInput
    {
        throw new RuntimeException('wp3 card presenter failure probe');
    }
}
