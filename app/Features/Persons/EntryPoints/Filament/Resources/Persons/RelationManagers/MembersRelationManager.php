<?php

namespace App\Features\Persons\EntryPoints\Filament\Resources\Persons\RelationManagers;

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Platform\Admin\AdminResourceLink;
use App\Shared\Submission\PublicSubmissionUiEvents;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class MembersRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'members';

    #[\Override]
    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (User $record): ?string => (auth()->user()?->can('update', $record) ?? false)
                        ? AdminResourceLink::toSlug('authz/users', 'edit', (string) $record->getKey())
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
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search): array {
                                $trimmed = trim($search);
                                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($trimmed));

                                return User::query()
                                    ->when($escaped !== '', function ($query) use ($escaped): void {
                                        $query->where(function ($nested) use ($escaped): void {
                                            $nested->whereRaw('LOWER(name) LIKE ?', ["%{$escaped}%"])
                                                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$escaped}%"]);
                                        });
                                    })
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get(['id', 'name', 'email'])
                                    ->mapWithKeys(static fn (User $user): array => [(string) $user->getKey() => "{$user->name} ({$user->email})"])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function (mixed $value): ?string {
                                if (! is_string($value) || $value === '') {
                                    return null;
                                }

                                $user = User::query()->find($value, ['id', 'name', 'email']);

                                return $user instanceof User ? "{$user->name} ({$user->email})" : null;
                            })
                            ->required(),
                        $this->makeRoleSelect(),
                    ])
                    ->action(function (array $data): void {
                        DB::transaction(function () use ($data): void {
                            $person = $this->getPersonOwner();
                            $person->lockForMembershipMutation();
                            $member = User::findOrFail($data['user_id']);
                            $role = MemberRole::tryFrom((string) ($data['role_id'] ?? '')) ?? MemberRole::Owner;

                            app(MembershipCommands::class)->addMember(
                                MemberSubjectType::Person,
                                (string) $person->getKey(),
                                (string) $member->getKey(),
                                $role->value,
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
                        DB::transaction(function () use ($data, $record): void {
                            $person = $this->getPersonOwner();
                            $person->lockForMembershipMutation();
                            $role = MemberRole::tryFrom((string) ($data['role_id'] ?? '')) ?? MemberRole::Viewer;

                            app(MembershipCommands::class)->changeMemberRole(
                                MemberSubjectType::Person,
                                (string) $person->getKey(),
                                (string) $record->getKey(),
                                $role->value,
                            );
                        });

                        $this->notifyOwnerEditPage();
                    }),
                Action::make('removeMember')
                    ->label('Remove')
                    ->color('danger')
                    ->hidden(fn (User $record): bool => $this->memberHasProtectedRole($record))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        DB::transaction(function () use ($record): void {
                            $person = $this->getPersonOwner();
                            $person->lockForMembershipMutation();

                            app(MembershipCommands::class)->removeMember(
                                MemberSubjectType::Person,
                                (string) $person->getKey(),
                                (string) $record->getKey(),
                            );
                        });

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

    private function getPersonOwner(): Person
    {
        /** @var Person $person */
        $person = $this->getOwnerRecord();

        return $person;
    }

    private function getMemberRoleId(User $user): ?string
    {
        $member = $this->getPersonOwner()->members()->whereKey($user->getKey())->first();

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
        $this->dispatch(PublicSubmissionUiEvents::REFRESH_TOGGLE);
    }
}
