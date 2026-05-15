<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionSubjectPresentationAction
{
    use AsAction;

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string}
     */
    public function handle(Event|Institution|Reference|Speaker $entity): array
    {
        return [
            'subject_label' => match (true) {
                $entity instanceof Institution => __('Institution'),
                $entity instanceof Speaker => __('Speaker'),
                $entity instanceof Reference => __('Reference'),
                default => __('Event'),
            },
            'subject_title' => match (true) {
                $entity instanceof Institution => $entity->name,
                $entity instanceof Speaker => $entity->formatted_name,
                $entity instanceof Reference => $entity->title,
                default => $entity->title,
            },
            'redirect_url' => match (true) {
                $entity instanceof Institution => route('institutions.show', $entity),
                $entity instanceof Speaker => route('speakers.show', $entity),
                $entity instanceof Reference => route('references.show', $entity),
                default => route('events.show', $entity),
            },
        ];
    }
}
