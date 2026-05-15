<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authz\UserResource\Pages;

use App\Actions\Membership\ChangeSubjectMemberRole;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\Authz\UserResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<string, string> */
    public array $protectedRoleSelections = [];

    #[\Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->reloadUserRecord();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['roles'], $data['permissions']);

        return $data;
    }

    /**
     * @return list<array{
     *     subject_type: string,
     *     title: string,
     *     current_role: string,
     *     membership_count: int,
     *     membership_labels: list<string>,
     *     options: array<string, string>,
     *     selection: string
     * }>
     */
    public function protectedScopedRoleManagers(): array
    {
        $user = $this->userRecord();
        $catalog = app(MemberRoleCatalog::class);

        return collect(MemberSubjectType::cases())
            ->map(function (MemberSubjectType $subjectType) use ($catalog, $user): ?array {
                $currentRoleName = $catalog->currentRoleName($user, $subjectType);

                if ($currentRoleName === null || ! $catalog->isProtectedRole($subjectType, $currentRoleName)) {
                    return null;
                }

                $membershipLabels = $this->membershipsFor($subjectType);
                $definitions = $catalog->definitionsFor($subjectType);
                $currentRoleLabel = (string) Arr::get($definitions, "{$currentRoleName}.label", Str::headline($currentRoleName));

                return [
                    'subject_type' => $subjectType->value,
                    'title' => Str::headline($subjectType->value).' Ownership',
                    'current_role' => $currentRoleLabel,
                    'membership_count' => count($membershipLabels),
                    'membership_labels' => $membershipLabels,
                    'options' => ['' => 'No scoped role'] + $this->roleOptionsFor($subjectType),
                    'selection' => $this->protectedRoleSelections[$subjectType->value] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function applyProtectedScopedRole(string $subjectTypeValue): void
    {
        $subjectType = MemberSubjectType::tryFrom($subjectTypeValue);

        abort_if(! ($subjectType instanceof MemberSubjectType), 404);

        $user = $this->userRecord();
        $catalog = app(MemberRoleCatalog::class);
        $currentRoleName = $catalog->currentRoleName($user, $subjectType);

        if ($currentRoleName === null || ! $catalog->isProtectedRole($subjectType, $currentRoleName)) {
            Notification::make()
                ->title('No protected scoped role is currently assigned for this membership type.')
                ->danger()
                ->send();

            return;
        }

        $selectedRoleId = $this->protectedRoleSelections[$subjectType->value] ?? '';

        app(ChangeSubjectMemberRole::class)->handle(
            $subjectType,
            $user,
            $selectedRoleId !== '' ? $selectedRoleId : null,
            allowProtectedRoleChange: true,
        );

        $this->reloadUserRecord();

        Notification::make()
            ->title(Str::headline($subjectType->value).' protected role updated.')
            ->success()
            ->send();
    }

    private function userRecord(): User
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function roleOptionsFor(MemberSubjectType $subjectType): array
    {
        $catalog = app(MemberRoleCatalog::class);
        $definitions = $catalog->definitionsFor($subjectType);

        return collect($catalog->roleOptionsFor($subjectType))
            ->mapWithKeys(fn (string $roleName, string $roleId): array => [
                $roleId => (string) Arr::get($definitions, "{$roleName}.label", Str::headline($roleName)),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function membershipsFor(MemberSubjectType $subjectType): array
    {
        $user = $this->userRecord();

        return match ($subjectType) {
            MemberSubjectType::Institution => $user->institutions->map(fn (Institution $institution): string => $institution->name)->values()->all(),
            MemberSubjectType::Speaker => $user->speakers->map(fn (Speaker $speaker): string => $speaker->name)->values()->all(),
            MemberSubjectType::Event => $user->memberEvents->map(fn (Event $event): string => $event->title)->values()->all(),
            MemberSubjectType::Reference => $user->references->map(fn (Reference $reference): string => $reference->title)->values()->all(),
        };
    }

    private function reloadUserRecord(): void
    {
        /** @var User $freshUser */
        $freshUser = User::query()
            ->findOrFail($this->userRecord()->getKey());

        $freshUser->load([
            'institutions' => fn ($query) => $query->orderBy('name'),
            'speakers' => fn ($query) => $query->orderBy('name'),
            'memberEvents' => fn ($query) => $query->orderBy('title'),
            'references' => fn ($query) => $query->orderBy('title'),
        ]);

        $this->record = $freshUser;

        $catalog = app(MemberRoleCatalog::class);

        foreach (MemberSubjectType::cases() as $subjectType) {
            $this->protectedRoleSelections[$subjectType->value] = $catalog->roleIdsFor($freshUser, $subjectType)[0] ?? '';
        }
    }
}
