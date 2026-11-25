<?php

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestModerationActions;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Features\Contributions\Models\ContributionRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewContributionRequest extends ViewRecord
{
    #[\Override]
    protected static string $resource = ContributionRequestResource::class;

    #[\Override]
    protected Width|string|null $maxContentWidth = Width::Full;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ContributionRequestModerationActions::approveAction(redirectToView: true),
            ContributionRequestModerationActions::rejectAction(redirectToView: true),
            Action::make('view_entity')
                ->label('Open Record')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): ?string => ContributionRequestPresenter::entityAdminUrl($this->requestRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => filled(ContributionRequestPresenter::entityAdminUrl($this->requestRecord()))),
        ];
    }

    private function requestRecord(): ContributionRequest
    {
        /** @var ContributionRequest $record */
        $record = $this->getRecord();

        return $record;
    }
}
