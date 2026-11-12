<?php

namespace App\Filament\Resources\ContributionRequests\Pages;

use App\Actions\Contributions\Review\ApproveContributionRequestAction;
use App\Actions\Contributions\Review\RejectContributionRequestAction;
use App\Enums\ContributionRequestStatus;
use App\Exceptions\ContributionRequestAlreadyReviewedException;
use App\Exceptions\ContributionRequestNotPendingException;
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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

class ViewContributionRequest extends ViewRecord
{
    #[\Override]
    protected static string $resource = ContributionRequestResource::class;

    #[\Override]
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

                try {
                    $approveContributionRequestAction->handle(
                        $this->requestRecord(),
                        $user,
                        filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                    );
                } catch (ContributionRequestAlreadyReviewedException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    $this->redirect(ContributionRequestResource::getUrl('view', ['record' => $this->requestRecord()]), navigate: true);

                    return;
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();

                    Notification::make()
                        ->title(is_string($message) && $message !== '' ? $message : __('Could not approve this request.'))
                        ->danger()
                        ->send();

                    return;
                } catch (LockTimeoutException) {
                    Notification::make()
                        ->title(__('The review service is busy. Please try again in a moment.'))
                        ->danger()
                        ->send();

                    $this->redirect(ContributionRequestResource::getUrl('view', ['record' => $this->requestRecord()]), navigate: true);

                    return;
                } catch (ContributionRequestNotPendingException) {
                    Notification::make()
                        ->title(__('This request is no longer pending review. Please refresh to see its current status.'))
                        ->danger()
                        ->send();

                    $this->redirect(ContributionRequestResource::getUrl('view', ['record' => $this->requestRecord()]), navigate: true);

                    return;
                }

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

                try {
                    $rejectContributionRequestAction->handle(
                        $this->requestRecord(),
                        $user,
                        (string) $data['reason_code'],
                        filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                    );
                } catch (ContributionRequestNotPendingException) {
                    Notification::make()
                        ->title(__('This request is no longer pending review. Please refresh to see its current status.'))
                        ->danger()
                        ->send();

                    $this->redirect(ContributionRequestResource::getUrl('view', ['record' => $this->requestRecord()]), navigate: true);

                    return;
                }

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
