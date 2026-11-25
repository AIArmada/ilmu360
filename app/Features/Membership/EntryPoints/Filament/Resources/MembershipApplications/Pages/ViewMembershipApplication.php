<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support\MembershipApplicationModerationActions;
use App\Features\Membership\Models\MembershipApplication;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewMembershipApplication extends ViewRecord
{
    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected static string $resource = MembershipApplicationResource::class;

    #[\Override]
    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected function getHeaderActions(): array
    {
        $redirectAfter = function (MembershipApplication $record): void {
            $this->redirect(MembershipApplicationResource::getUrl('view', ['record' => $record]), navigate: true);
        };

        return [
            MembershipApplicationModerationActions::approveAction($redirectAfter),
            MembershipApplicationModerationActions::rejectAction($redirectAfter),
            MembershipApplicationModerationActions::openSubjectAction(),
        ];
    }
}
