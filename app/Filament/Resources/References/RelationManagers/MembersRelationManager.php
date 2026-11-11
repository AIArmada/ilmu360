<?php

namespace App\Filament\Resources\References\RelationManagers;

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Membership\Api\MembershipCommands;
use App\Filament\Resources\Authz\UserResource as AuthzUserResource;
use App\Models\Reference;
use App\Models\User;
use App\Shared\Membership\MembershipSubjectType;
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
                        $reference = $this->getReferenceOwner();
                        $member = User::findOrFail($data['user_id']);
                        $role = MemberRole::tryFrom((string) ($data['role_id'] ?? '')) ?? MemberRole::Owner;

                        app(MembershipCommands::class)->addMember(
                            MembershipSubjectType::Reference,
                            (string) $reference->getKey(),
                            (string) $member->getKey(),
                            $role->value,
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
                        $reference = $this->getReferenceOwner();
                        $role = MemberRole::tryFrom((string) ($data['role_id'] ?? '')) ?? MemberRole::Viewer;

                        app(MembershipCommands::class)->changeMemberRole(
                            MembershipSubjectType::Reference,
                            (string) $reference->getKey(),
                            (string) $record->getKey(),
                            $role->value,
                        );

                        $this->notifyOwnerEditPage();
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->color('danger')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $reference = $this->getReferenceOwner();

                        app(MembershipCommands::class)->removeMember(
                            MembershipSubjectType::Reference,
                            (string) $reference->getKey(),
                            (string) $record->getKey(),
                        );

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

    private function getReferenceOwner(): Reference
    {
        /** @var Reference $reference */
        $reference = $this->getOwnerRecord();

        return $reference;
    }

    private function getMemberRoleId(User $user): ?string
    {
        $member = $this->getReferenceOwner()->members()->whereKey($user->getKey())->first();

        return $member?->pivot->getAttribute('role');
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
