<?php

declare(strict_types=1);

namespace Tests\Support;

use AIArmada\Events\Contracts\EventSearchRelationProvider;

final class RecordingEventSearchRelationProvider implements EventSearchRelationProvider
{
    public int $calls = 0;

    public function relations(): array
    {
        $this->calls++;

        return ['languageRecords'];
    }
}
