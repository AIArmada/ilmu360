<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authz\UserResource\Pages;

use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\Authz\UserResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
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

        return collect(MemberSubjectType::cases())
            ->map(function (MemberSubjectType $subjectType) use ($user): ?array {
                $pivotRole = $this->pivotRoleSlug($user, $subjectType);

                if ($pivotRole === null || $pivotRole !== MemberRole::Owner->value) {
                    return null;
                }

                $membershipLabels = $this->membershipsFor($subjectType);
                $currentRoleLabel = MemberRole::tryFrom($pivotRole)?->label() ?? Str::headline($pivotRole);

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
        $pivotRole = $this->pivotRoleSlug($user, $subjectType);

        if ($pivotRole === null || $pivotRole !== MemberRole::Owner->value) {
            Notification::make()
                ->title('No protected scoped role is currently assigned for this membership type.')
                ->danger()
                ->send();

            return;
        }

        $selectedRoleId = $this->protectedRoleSelections[$subjectType->value] ?? '';

        $role = $selectedRoleId !== '' ? MemberRole::tryFrom($selectedRoleId) : null;

        if ($role !== null) {
            $subject = $this->firstResolvedSubject($subjectType, $user);

            if ($subject !== null) {
                app(ChangeMemberRoleAction::class)->handle(
                    $subject,
                    $user,
                    $role,
                );
            }
        }

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
        return collect(MemberRole::cases())
            ->mapWithKeys(fn (MemberRole $r): array => [$r->value => $r->label()])
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

        foreach (MemberSubjectType::cases() as $subjectType) {
            $pivotSlug = $this->pivotRoleSlug($freshUser, $subjectType);
            $this->protectedRoleSelections[$subjectType->value] = $pivotSlug ?? '';
        }
    }

    private function pivotRoleSlug(User $user, MemberSubjectType $subjectType): ?string
    {
        $pivot = match ($subjectType) {
            MemberSubjectType::Institution => $user->institutions->first()?->getRelationValue('pivot'),
            MemberSubjectType::Speaker => $user->speakers->first()?->getRelationValue('pivot'),
            MemberSubjectType::Event => $user->memberEvents->first()?->getRelationValue('pivot'),
            MemberSubjectType::Reference => $user->references->first()?->getRelationValue('pivot'),
        };

        $role = $pivot?->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    private function firstResolvedSubject(MemberSubjectType $subjectType, User $user): Institution|Speaker|Event|Reference|null
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => $user->institutions->first(),
            MemberSubjectType::Speaker => $user->speakers->first(),
            MemberSubjectType::Event => $user->memberEvents->first(),
            MemberSubjectType::Reference => $user->references->first(),
        };
    }
}
