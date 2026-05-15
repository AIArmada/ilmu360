<?php

namespace App\Filament\Resources\Institutions\RelationManagers;

use App\Actions\Membership\AddMemberToSubject;
use App\Actions\Membership\ChangeSubjectMemberRole;
use App\Actions\Membership\RemoveMemberFromSubject;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\Authz\UserResource as AuthzUserResource;
use App\Models\Institution;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\ScopedMemberRoleSeeder;
use App\Support\Submission\PublicSubmissionUiEvents;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (User $record): ?string => AuthzUserResource::canEdit($record)
                        ? AuthzUserResource::getUrl('edit', ['record' => $record], panel: 'admin')
                        : null),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->getStateUsing(fn (User $record): string => implode(', ', app(MemberRoleCatalog::class)->roleNamesFor($record, MemberSubjectType::Institution)) ?: '—'),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add member')
                    ->form([
                        Select::make('user_id')
                            ->label('User')
                            ->options(User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        $this->makeRoleSelect(),
                    ])
                    ->action(function (array $data): void {
                        app(AddMemberToSubject::class)->handle(
                            $this->getInstitutionOwner(),
                            User::findOrFail($data['user_id']),
                            $data['role_id'] ?? null,
                        );

                        $this->notifyOwnerEditPage();
                    }),
            ])
            ->actions([
                Action::make('manageRoles')
                    ->label('Roles')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->form([
                        $this->makeRoleSelect(),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'role_id' => $this->getMemberRoleId($record),
                    ])
                    ->action(function (array $data, User $record): void {
                        app(ChangeSubjectMemberRole::class)->handle(
                            $this->getInstitutionOwner(),
                            $record,
                            $data['role_id'] ?? null,
                        );

                        $this->notifyOwnerEditPage();
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->color('danger')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(RemoveMemberFromSubject::class)->handle($this->getInstitutionOwner(), $record);

                        $this->notifyOwnerEditPage();
                    }),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function getScopedRoleOptions(): array
    {
        app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

        return app(MemberRoleCatalog::class)->roleOptionsFor(MemberSubjectType::Institution);
    }

    private function getInstitutionOwner(): Institution
    {
        /** @var Institution $institution */
        $institution = $this->getOwnerRecord();

        return $institution;
    }

    private function getMemberRoleId(User $user): ?string
    {
        return app(MemberRoleCatalog::class)->roleIdsFor($user, MemberSubjectType::Institution)[0] ?? null;
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
        return app(MemberRoleCatalog::class)->userHasProtectedRole($user, MemberSubjectType::Institution);
    }

    private function notifyOwnerEditPage(): void
    {
        $this->dispatch(PublicSubmissionUiEvents::REFRESH_TOGGLE)->to($this->getPageClass());
    }
}
