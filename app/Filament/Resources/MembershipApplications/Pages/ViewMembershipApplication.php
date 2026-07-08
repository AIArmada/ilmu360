<?php

namespace App\Filament\Resources\MembershipApplications\Pages;

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Membership\MembershipApplicationPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewMembershipApplication extends ViewRecord
{
    protected static string $resource = MembershipApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->getApproveAction(),
            $this->getRejectAction(),
            Action::make('open_subject')
                ->label('Open Record')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): ?string => MembershipApplicationPresenter::subjectAdminUrl($this->applicationRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => filled(MembershipApplicationPresenter::subjectAdminUrl($this->applicationRecord()))),
        ];
    }

    protected function getApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Membership Application')
            ->modalDescription('Approve this application and choose the role to grant.')
            ->schema([
                Select::make('granted_role')
                    ->label('Granted Role')
                    ->options(MembershipApplicationPresenter::approvalRoleOptions($this->applicationRecord()))
                    ->required(),
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ApproveMembershipApplicationAction $approveMembershipApplicationAction): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                $approveMembershipApplicationAction->handle(
                    $this->applicationRecord(),
                    $user,
                    MemberRole::tryFrom((string) $data['granted_role']) ?? MemberRole::Editor,
                    filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                );

                Notification::make()
                    ->title('Membership application approved')
                    ->success()
                    ->send();

                $this->redirect(MembershipApplicationResource::getUrl('view', ['record' => $this->applicationRecord()]), navigate: true);
            })
            ->visible(fn (): bool => $this->applicationRecord()->status === ApplicationStatus::Pending);
    }

    protected function getRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject Membership Application')
            ->modalDescription('Reject this application and optionally leave guidance for the applicant.')
            ->schema([
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, RejectMembershipApplicationAction $rejectMembershipApplicationAction): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                $rejectMembershipApplicationAction->handle(
                    $this->applicationRecord(),
                    $user,
                    filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                );

                Notification::make()
                    ->title('Membership application rejected')
                    ->danger()
                    ->send();

                $this->redirect(MembershipApplicationResource::getUrl('view', ['record' => $this->applicationRecord()]), navigate: true);
            })
            ->visible(fn (): bool => $this->applicationRecord()->status === ApplicationStatus::Pending);
    }

    private function applicationRecord(): MembershipApplication
    {
        /** @var MembershipApplication $record */
        $record = $this->getRecord();

        return $record;
    }
}
