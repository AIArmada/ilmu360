<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionSubjectPresentationAction
{
    use AsAction;

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string}
     */
    public function handle(Event|Institution|Reference|Person $entity): array
    {
        return [
            'subject_label' => match (true) {
                $entity instanceof Institution => __('institusi'),
                $entity instanceof Person => __('penceramah'),
                $entity instanceof Reference => __('rujukan'),
                default => __('majlis'),
            },
            'subject_title' => match (true) {
                $entity instanceof Institution => $entity->name,
                $entity instanceof Person => $entity->formatted_name,
                $entity instanceof Reference => $entity->title,
                default => $entity->title,
            },
            'redirect_url' => match (true) {
                $entity instanceof Institution => route('institutions.show', $entity),
                $entity instanceof Person => route('persons.show', $entity),
                $entity instanceof Reference => route('references.show', $entity),
                default => route('events.show', $entity),
            },
        ];
    }
}
