<?php

namespace App\Filament\Resources\Institutions\RelationManagers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Filament\Resources\Authz\UserResource as AuthzUserResource;
use App\Models\Institution;
use App\Models\User;
use App\Support\Submission\PublicSubmissionUiEvents;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
                TextColumn::make('role')
                    ->label('Role')
                    ->getStateUsing(fn (User $record): string => $record->pivot->role ?? '—'),
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
                        OwnerContext::withOwner($this->getInstitutionOwner(), function () use ($data): void {
                            app(AddMemberAction::class)->handle(
                                $this->getInstitutionOwner(),
                                User::findOrFail($data['user_id']),
                                MemberRole::tryFrom((string) ($data['role_id'] ?? '')) ?? MemberRole::Owner,
                            );
                        });

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
                        app(ChangeMemberRoleAction::class)->handle(
                            $this->getInstitutionOwner(),
                            $record,
                            MemberRole::tryFrom((string) ($data['role_id'] ?? '')) ?? MemberRole::Viewer,
                        );

                        $this->notifyOwnerEditPage();
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->color('danger')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(RemoveMemberAction::class)->handle($this->getInstitutionOwner(), $record);

                        $this->notifyOwnerEditPage();
                    }),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function roleOptions(): array
    {
        return collect(MemberRole::cases())
            ->mapWithKeys(fn (MemberRole $role): array => [$role->value => $role->label()])
            ->all();
    }

    private function getInstitutionOwner(): Institution
    {
        /** @var Institution $institution */
        $institution = $this->getOwnerRecord();

        return $institution;
    }

    private function getMemberRoleId(User $user): ?string
    {
        $institution = $this->getInstitutionOwner();
        /** @var Collection<int, User> $members */
        $members = $institution->relationLoaded('members')
            ? $institution->members
            : $institution->members()->get();

        if (! $institution->relationLoaded('members')) {
            $institution->setRelation('members', $members);
        }

        $member = $members->first(fn (User $member): bool => (string) $member->getKey() === (string) $user->getKey());

        $pivot = $member?->getRelation('pivot');

        return $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;
    }

    private function makeRoleSelect(): Select
    {
        return Select::make('role_id')
            ->label('Role')
            ->options(fn () => $this->roleOptions())
            ->required();
    }

    private function memberHasProtectedRole(User $user): bool
    {
        $role = $this->getMemberRoleId($user);

        return $role === MemberRole::Owner->value;
    }

    private function notifyOwnerEditPage(): void
    {
        $this->dispatch(PublicSubmissionUiEvents::REFRESH_TOGGLE)->to($this->getPageClass());
    }
}
