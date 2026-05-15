<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\Institution;
use App\Models\Speaker;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveMembershipClaimSubjectPresentationAction
{
    use AsAction;

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string}
     */
    public function handle(Institution|Speaker $subject): array
    {
        return [
            'subject_label' => match (true) {
                $subject instanceof Institution => __('Institution'),
                default => __('Speaker'),
            },
            'subject_title' => match (true) {
                $subject instanceof Institution => $subject->name,
                default => $subject->formatted_name,
            },
            'redirect_url' => match (true) {
                $subject instanceof Institution => route('institutions.show', $subject),
                default => route('speakers.show', $subject),
            },
            'admin_url' => match (true) {
                $subject instanceof Institution => InstitutionResource::getUrl('view', ['record' => $subject], panel: 'admin'),
                default => SpeakerResource::getUrl('view', ['record' => $subject], panel: 'admin'),
            },
        ];
    }
}
