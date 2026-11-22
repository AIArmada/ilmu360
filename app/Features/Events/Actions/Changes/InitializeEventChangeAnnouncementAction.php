<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Changes;

use App\Features\Events\Models\EventChangeAnnouncement;

/** Provide canonical announcement fields when the event update is created. */
final readonly class InitializeEventChangeAnnouncementAction
{
    public function handle(EventChangeAnnouncement $announcement): void
    {
        if (empty($announcement->getAttribute('title'))) {
            $announcement->setAttribute('title', $announcement->getAttribute('message') ?? 'Pengumuman perubahan');
        }

        if (empty($announcement->getAttribute('visibility'))) {
            $announcement->setAttribute('visibility', 'public');
        }
    }
}
