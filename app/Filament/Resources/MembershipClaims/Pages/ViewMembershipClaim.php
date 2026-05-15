<?php

namespace App\Filament\Resources\MembershipClaims\Pages;

use App\Actions\Membership\ApproveMembershipClaimAction;
use App\Actions\Membership\RejectMembershipClaimAction;
use App\Enums\MembershipClaimStatus;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Models\MembershipClaim;
use App\Models\User;
use App\Support\Membership\MembershipClaimPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewMembershipClaim extends ViewRecord
{
    protected static string $resource = MembershipClaimResource::class;

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
                ->url(fn (): ?string => MembershipClaimPresenter::subjectAdminUrl($this->claimRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => filled(MembershipClaimPresenter::subjectAdminUrl($this->claimRecord()))),
        ];
    }

    protected function getApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Membership Claim')
            ->modalDescription('Approve this claim and choose the role to grant.')
            ->schema([
                Select::make('granted_role_slug')
                    ->label('Granted Role')
                    ->options(MembershipClaimPresenter::approvalRoleOptions($this->claimRecord()))
                    ->required(),
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ApproveMembershipClaimAction $approveMembershipClaimAction): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                $approveMembershipClaimAction->handle(
                    $this->claimRecord(),
                    $user,
                    (string) $data['granted_role_slug'],
                    filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                );

                Notification::make()
                    ->title('Membership claim approved')
                    ->success()
                    ->send();

                $this->redirect(MembershipClaimResource::getUrl('view', ['record' => $this->claimRecord()]), navigate: true);
            })
            ->visible(fn (): bool => $this->claimRecord()->status === MembershipClaimStatus::Pending);
    }

    protected function getRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject Membership Claim')
            ->modalDescription('Reject this claim and optionally leave guidance for the claimant.')
            ->schema([
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, RejectMembershipClaimAction $rejectMembershipClaimAction): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                $rejectMembershipClaimAction->handle(
                    $this->claimRecord(),
                    $user,
                    filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                );

                Notification::make()
                    ->title('Membership claim rejected')
                    ->danger()
                    ->send();

                $this->redirect(MembershipClaimResource::getUrl('view', ['record' => $this->claimRecord()]), navigate: true);
            })
            ->visible(fn (): bool => $this->claimRecord()->status === MembershipClaimStatus::Pending);
    }

    private function claimRecord(): MembershipClaim
    {
        /** @var MembershipClaim $record */
        $record = $this->getRecord();

        return $record;
    }
}
