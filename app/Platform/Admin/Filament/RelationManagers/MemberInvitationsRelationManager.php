<?php

declare(strict_types=1);

namespace App\Platform\Admin\Filament\RelationManagers;

use AIArmada\Membership\Actions\InviteMemberAction;
use AIArmada\Membership\Enums\InvitationStatus;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Models\MembershipInvitation;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Api\MemberInvitationAccess;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared member-invitations relation manager for membership subjects.
 *
 * Subject features extend this class with their subject type and owner; the
 * invitation gate crosses the Membership API boundary.
 */
abstract class MemberInvitationsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'memberInvitations';

    #[\Override]
    protected static ?string $title = 'Member Invitations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $type = MemberSubjectType::tryFrom($ownerRecord->getMorphClass());

        return $type instanceof MemberSubjectType
            && parent::canViewForRecord($ownerRecord, $pageClass)
            && app(MemberInvitationAccess::class)->canInvite($user, $type, (string) $ownerRecord->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['inviter', 'acceptedBy', 'revokedBy'])->latest('created_at'))
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => MemberRole::tryFrom($state)?->label() ?? $state),
                TextColumn::make('inviter.name')
                    ->label('Invited by')
                    ->default('—'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d M Y h:i A')
                    ->placeholder('No expiry')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (MembershipInvitation $record): string => $this->statusLabel($record))
                    ->color(fn (string $state): string => $this->statusColor($state)),
                TextColumn::make('delivery')
                    ->label('Delivery')
                    ->state('Email only'),
            ])
            ->headerActions([
                Action::make('inviteMember')
                    ->label('Invite member')
                    ->form([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Select::make('role')
                            ->label('Role')
                            ->options(fn (): array => collect(MemberRole::cases())->mapWithKeys(fn (MemberRole $role): array => [$role->value => $role->label()])->all())
                            ->required(),
                        DateTimePicker::make('expires_at')
                            ->label('Expires at')
                            ->seconds(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth()->user();

                        app(InviteMemberAction::class)->handle(
                            $this->getSubjectOwner(),
                            (string) $data['email'],
                            MemberRole::tryFrom((string) $data['role']) ?? MemberRole::Viewer,
                            $user,
                            $this->normalizeExpiresAt($data['expires_at'] ?? null),
                        );
                    }),
            ])
            ->actions([
                Action::make('revokeInvitation')
                    ->label('Revoke')
                    ->color('danger')
                    ->hidden(fn (MembershipInvitation $record): bool => $record->isAccepted()
                        || $record->isRevoked()
                        || $record->status === InvitationStatus::Expired)
                    ->requiresConfirmation()
                    ->action(function (MembershipInvitation $record): void {
                        /** @var User $user */
                        $user = auth()->user();

                        app(MembershipInvitations::class)->revoke(
                            (string) $record->getKey(),
                            (string) $user->getKey(),
                        );
                    }),
            ]);
    }

    abstract protected function getSubjectType(): MemberSubjectType;

    abstract protected function getSubjectOwner(): Model;

    private function statusLabel(MembershipInvitation $invitation): string
    {
        return match (true) {
            $invitation->isAccepted() => 'Accepted',
            $invitation->isRevoked() => 'Revoked',
            $invitation->isExpired() => 'Expired',
            default => 'Pending',
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'Accepted' => 'success',
            'Revoked' => 'danger',
            'Expired' => 'warning',
            default => 'gray',
        };
    }

    private function normalizeExpiresAt(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
