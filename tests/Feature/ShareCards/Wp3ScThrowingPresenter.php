<?php

declare(strict_types=1);

namespace Tests\Feature\ShareCards;

use AIArmada\Events\Models\EventOccurrence;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Models\Event;
use App\Support\ShareCards\ShareCardEventPresenter;
use RuntimeException;

final readonly class Wp3ScThrowingPresenter extends ShareCardEventPresenter
{
    public function toRenderInput(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): ShareCardRenderInput
    {
        throw new RuntimeException('wp3 card presenter failure probe');
    }
}
