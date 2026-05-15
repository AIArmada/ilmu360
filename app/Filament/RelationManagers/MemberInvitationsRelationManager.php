<?php

namespace App\Filament\RelationManagers;

use App\Actions\Membership\InviteSubjectMember;
use App\Actions\Membership\RevokeSubjectMemberInvitation;
use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberInvitationGate;
use App\Support\Authz\MemberRoleCatalog;
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

abstract class MemberInvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberInvitations';

    protected static ?string $title = 'Member Invitations';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass)
            && (
                $ownerRecord instanceof Institution
                || $ownerRecord instanceof Speaker
                || $ownerRecord instanceof Event
                || $ownerRecord instanceof Reference
            )
            && auth()->user() instanceof User
            && app(MemberInvitationGate::class)->canInvite(auth()->user(), $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['inviter', 'acceptedBy', 'revokedBy'])->latest('created_at'))
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role_slug')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(MemberRoleCatalog::class)->roleLabel($this->getSubjectType(), $state)),
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
                    ->getStateUsing(fn (MemberInvitation $record): string => $this->statusLabel($record))
                    ->color(fn (string $state): string => $this->statusColor($state)),
                TextColumn::make('accept_link')
                    ->label('Accept link')
                    ->state(fn (MemberInvitation $record): string => route('member-invitations.show', ['token' => $record->token]))
                    ->copyable()
                    ->copyMessage('Invitation link copied')
                    ->limit(36),
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
                        Select::make('role_slug')
                            ->label('Role')
                            ->options(fn (): array => app(MemberRoleCatalog::class)->invitationRoleSlugOptionsFor($this->getSubjectType()))
                            ->required(),
                        DateTimePicker::make('expires_at')
                            ->label('Expires at')
                            ->seconds(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth()->user();

                        app(InviteSubjectMember::class)->handle(
                            $this->getSubjectOwner(),
                            (string) $data['email'],
                            (string) $data['role_slug'],
                            $user,
                            $this->normalizeExpiresAt($data['expires_at'] ?? null),
                        );
                    }),
            ])
            ->actions([
                Action::make('revokeInvitation')
                    ->label('Revoke')
                    ->color('danger')
                    ->hidden(fn (MemberInvitation $record): bool => $record->isAccepted() || $record->isRevoked())
                    ->requiresConfirmation()
                    ->action(function (MemberInvitation $record): void {
                        /** @var User $user */
                        $user = auth()->user();

                        app(RevokeSubjectMemberInvitation::class)->handle($record, $user);
                    }),
            ]);
    }

    abstract protected function getSubjectType(): MemberSubjectType;

    abstract protected function getSubjectOwner(): Institution|Speaker|Event|Reference;

    private function statusLabel(MemberInvitation $invitation): string
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
