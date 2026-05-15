@php
    use App\Enums\MemberSubjectType;
    use App\Filament\Resources\Events\EventResource;
    use App\Filament\Resources\Institutions\InstitutionResource;
    use App\Filament\Resources\References\ReferenceResource;
    use App\Filament\Resources\Speakers\SpeakerResource;
    use App\Models\User;
    use App\Support\Authz\MemberRoleCatalog;

    /** @var User|null $user */
    $user = $record instanceof User ? $record : null;
    $memberRoleCatalog = app(MemberRoleCatalog::class);
    $institutionRoles = $user instanceof User ? implode(', ', $memberRoleCatalog->roleNamesFor($user, MemberSubjectType::Institution)) : '';
    $speakerRoles = $user instanceof User ? implode(', ', $memberRoleCatalog->roleNamesFor($user, MemberSubjectType::Speaker)) : '';
    $eventRoles = $user instanceof User ? implode(', ', $memberRoleCatalog->roleNamesFor($user, MemberSubjectType::Event)) : '';
    $referenceRoles = $user instanceof User ? implode(', ', $memberRoleCatalog->roleNamesFor($user, MemberSubjectType::Reference)) : '';
@endphp

@if (! $user instanceof User)
    <p class="text-sm text-gray-500">Save the user first to review memberships.</p>
@else
    <div class="space-y-6">
        <p class="text-sm text-gray-500">
            Membership roles are managed from the institution, speaker, event, or reference itself. This summary is read-only.
        </p>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="space-y-3">
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">Institutions</h3>
                @forelse ($user->institutions as $institution)
                    <div class="rounded-xl border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <a class="font-medium text-primary-600 hover:underline" href="{{ InstitutionResource::getUrl('edit', ['record' => $institution], panel: 'admin') }}">
                                {{ $institution->name }}
                            </a>
                            <span class="text-xs text-gray-500">
                                {{ $institutionRoles !== '' ? $institutionRoles : 'No role' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No institution memberships.</p>
                @endforelse
            </div>

            <div class="space-y-3">
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">Speakers</h3>
                @forelse ($user->speakers as $speaker)
                    <div class="rounded-xl border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <a class="font-medium text-primary-600 hover:underline" href="{{ SpeakerResource::getUrl('edit', ['record' => $speaker], panel: 'admin') }}">
                                {{ $speaker->name }}
                            </a>
                            <span class="text-xs text-gray-500">
                                {{ $speakerRoles !== '' ? $speakerRoles : 'No role' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No speaker memberships.</p>
                @endforelse
            </div>

            <div class="space-y-3">
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">Events</h3>
                @forelse ($user->memberEvents as $event)
                    <div class="rounded-xl border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <a class="font-medium text-primary-600 hover:underline" href="{{ EventResource::getUrl('edit', ['record' => $event], panel: 'admin') }}">
                                {{ $event->title }}
                            </a>
                            <span class="text-xs text-gray-500">
                                {{ $eventRoles !== '' ? $eventRoles : 'No role' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No event memberships.</p>
                @endforelse
            </div>

            <div class="space-y-3">
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">References</h3>
                @forelse ($user->references as $reference)
                    <div class="rounded-xl border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <a class="font-medium text-primary-600 hover:underline" href="{{ ReferenceResource::getUrl('edit', ['record' => $reference], panel: 'admin') }}">
                                {{ $reference->title }}
                            </a>
                            <span class="text-xs text-gray-500">
                                {{ $referenceRoles !== '' ? $referenceRoles : 'No role' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No reference memberships.</p>
                @endforelse
            </div>
        </div>
    </div>
@endif
