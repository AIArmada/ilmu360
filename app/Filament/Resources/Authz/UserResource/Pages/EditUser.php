<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authz\UserResource\Pages;

use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Models\Organization;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\Authz\UserResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
     * Verification timestamps are deliberately excluded from User::$fillable.
     * This authorized admin surface may still change them, but only through an
     * explicit force fill after the ordinary user attributes are persisted.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User) {
            throw new \RuntimeException('Expected Filament record to be a User instance.');
        }

        $verificationData = $this->verificationData($data);
        unset($data['email_verified_at'], $data['phone_verified_at']);

        parent::handleRecordUpdate($record, $data);

        if ($verificationData !== []) {
            $record->forceFill($verificationData)->save();
        }

        return $record;
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
                    'options' => ['' => 'No scoped role'] + $this->roleOptionsFor(),
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
                if ($subject instanceof Person) {
                    DB::transaction(function () use ($role, $subject, $user): void {
                        $subject->lockForMembershipMutation();

                        app(ChangeMemberRoleAction::class)->handle($subject, $user, $role);
                    });
                } else {
                    app(ChangeMemberRoleAction::class)->handle($subject, $user, $role);
                }
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
    private function roleOptionsFor(): array
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
            MemberSubjectType::Person => $user->persons->map(fn (Person $person): string => $person->name)->values()->all(),
            MemberSubjectType::Event => $user->memberEvents->map(fn (Event $event): string => $event->title)->values()->all(),
            MemberSubjectType::Reference => $user->references->map(fn (Reference $reference): string => $reference->title)->values()->all(),
            MemberSubjectType::Organization => $user->organizations->map(fn (Organization $organization): string => $organization->name)->values()->all(),
        };
    }

    private function reloadUserRecord(): void
    {
        /** @var User $freshUser */
        $freshUser = User::query()
            ->findOrFail($this->userRecord()->getKey());

        $freshUser->load([
            'institutions' => fn ($query) => $query->orderBy('name'),
            'persons' => fn ($query) => $query->orderBy('name'),
            'memberEvents' => fn ($query) => $query->orderBy('title'),
            'references' => fn ($query) => $query->orderBy('title'),
            'organizations' => fn ($query) => $query->orderBy('name'),
        ]);

        $this->record = $freshUser;

        foreach (MemberSubjectType::cases() as $subjectType) {
            $pivotSlug = $this->pivotRoleSlug($freshUser, $subjectType);
            $this->protectedRoleSelections[$subjectType->value] = $pivotSlug ?? '';
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function verificationData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'email_verified_at',
            'phone_verified_at',
        ]));
    }

    private function pivotRoleSlug(User $user, MemberSubjectType $subjectType): ?string
    {
        $pivot = match ($subjectType) {
            MemberSubjectType::Institution => $user->institutions->first()?->getRelationValue('pivot'),
            MemberSubjectType::Person => $user->persons->first()?->getRelationValue('pivot'),
            MemberSubjectType::Event => $user->memberEvents->first()?->getRelationValue('pivot'),
            MemberSubjectType::Reference => $user->references->first()?->getRelationValue('pivot'),
            MemberSubjectType::Organization => $user->organizations->first()?->getRelationValue('pivot'),
        };

        $role = $pivot?->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    private function firstResolvedSubject(MemberSubjectType $subjectType, User $user): Institution|Person|Event|Reference|Organization|null
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => $user->institutions->first(),
            MemberSubjectType::Person => $user->persons->first(),
            MemberSubjectType::Event => $user->memberEvents->first(),
            MemberSubjectType::Reference => $user->references->first(),
            MemberSubjectType::Organization => $user->organizations->first(),
        };
    }
}
