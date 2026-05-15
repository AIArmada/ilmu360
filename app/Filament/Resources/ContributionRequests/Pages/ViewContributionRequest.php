<?php

namespace App\Filament\Resources\ContributionRequests\Pages;

use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\RejectContributionRequestAction;
use App\Enums\ContributionRequestStatus;
use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Models\ContributionRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewContributionRequest extends ViewRecord
{
    protected static string $resource = ContributionRequestResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->getApproveAction(),
            $this->getRejectAction(),
            Action::make('view_entity')
                ->label('Open Record')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): ?string => ContributionRequestPresenter::entityAdminUrl($this->requestRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => filled(ContributionRequestPresenter::entityAdminUrl($this->requestRecord()))),
        ];
    }

    protected function getApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Contribution Request')
            ->modalDescription('Approve this request and apply the proposed change.')
            ->schema([
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ApproveContributionRequestAction $approveContributionRequestAction): void {
                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                $approveContributionRequestAction->handle(
                    $this->requestRecord(),
                    $user,
                    filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                );

                Notification::make()
                    ->title('Contribution request approved')
                    ->success()
                    ->send();

                $this->redirect(ContributionRequestResource::getUrl('view', ['record' => $this->requestRecord()]), navigate: true);
            })
            ->visible(fn (): bool => $this->requestRecord()->status === ContributionRequestStatus::Pending);
    }

    protected function getRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject Contribution Request')
            ->modalDescription('Reject this request and record the moderation reason.')
            ->schema([
                Select::make('reason_code')
                    ->label('Reason')
                    ->options(ContributionRequestPresenter::rejectionReasonOptions())
                    ->required(),
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, RejectContributionRequestAction $rejectContributionRequestAction): void {
                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                $rejectContributionRequestAction->handle(
                    $this->requestRecord(),
                    $user,
                    (string) $data['reason_code'],
                    filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                );

                Notification::make()
                    ->title('Contribution request rejected')
                    ->danger()
                    ->send();

                $this->redirect(ContributionRequestResource::getUrl('view', ['record' => $this->requestRecord()]), navigate: true);
            })
            ->visible(fn (): bool => $this->requestRecord()->status === ContributionRequestStatus::Pending);
    }

    private function requestRecord(): ContributionRequest
    {
        /** @var ContributionRequest $record */
        $record = $this->getRecord();

        return $record;
    }
}
