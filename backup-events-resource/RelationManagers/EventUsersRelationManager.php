<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Actions\Membership\AddMemberToSubject;
use App\Actions\Membership\ChangeSubjectMemberRole;
use App\Actions\Membership\RemoveMemberFromSubject;
use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Team Members';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->getStateUsing(fn (User $record): string => implode(', ', app(MemberRoleCatalog::class)->roleNamesFor($record, MemberSubjectType::Event)) ?: '—'),
                TextColumn::make('pivot.joined_at')
                    ->label('Joined')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add Member')
                    ->form([
                        Select::make('user_id')
                            ->label('User')
                            ->options(function () {
                                $existingMemberIds = $this->getEventOwner()->members()->pluck('users.id')->toArray();

                                return User::query()
                                    ->whereNotIn('id', $existingMemberIds)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->required(),
                        $this->makeRoleSelect(),
                    ])
                    ->action(function (array $data): void {
                        app(AddMemberToSubject::class)->handle(
                            $this->getEventOwner(),
                            User::findOrFail($data['user_id']),
                            $data['role_id'] ?? null,
                        );
                    }),
            ])
            ->actions([
                Action::make('manageRoles')
                    ->label('Roles')
                    ->icon('heroicon-o-pencil')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->form([
                        $this->makeRoleSelect(),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'role_id' => $this->getMemberRoleId($record),
                    ])
                    ->action(function (array $data, User $record): void {
                        app(ChangeSubjectMemberRole::class)->handle(
                            $this->getEventOwner(),
                            $record,
                            $data['role_id'] ?? null,
                        );
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(RemoveMemberFromSubject::class)->handle($this->getEventOwner(), $record);
                    }),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function getScopedRoleOptions(): array
    {
        app(ScopedMemberRoleSeeder::class)->ensureForEvent();

        return app(MemberRoleCatalog::class)->roleOptionsFor(MemberSubjectType::Event);
    }

    private function getEventOwner(): Event
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return $event;
    }

    private function getMemberRoleId(User $user): ?string
    {
        return app(MemberRoleCatalog::class)->roleIdsFor($user, MemberSubjectType::Event)[0] ?? null;
    }

    private function makeRoleSelect(): Select
    {
        return Select::make('role_id')
            ->label('Role')
            ->options(fn () => $this->getScopedRoleOptions())
            ->required();
    }

    private function memberHasProtectedRole(User $user): bool
    {
        return app(MemberRoleCatalog::class)->userHasProtectedRole($user, MemberSubjectType::Event);
    }
}
