@php
    $statusValue = (string) $event->status;
    $isAwaitingApproval = $statusValue === 'pending';
    $canEditEvent = auth()->user()?->can('update', $event) ?? false;
    $ahliEventEditUrl = $canEditEvent
        ? \App\Filament\Ahli\Resources\Events\EventResource::getUrl('edit', ['record' => $event], panel: 'ahli')
        : null;
    $duplicateEventUrl = $canEditEvent && $canUseSelectedInstitutionForScopedSubmission && filled($selectedInstitutionId)
        ? route('dashboard.institutions.submit-event', ['institution' => $selectedInstitutionId, 'duplicate' => $event->id])
        : null;
    $createChildEventUrl = $event->isParentProgram()
        ? route('submit-event.create', ['parent' => $event->id])
        : null;
@endphp

<div class="{{ $isAwaitingApproval ? 'border-s-4 border-amber-400 ps-4' : '' }}">
    <a
        href="{{ route('events.show', $event) }}"
        wire:navigate
        class="font-semibold {{ $isAwaitingApproval ? 'text-amber-950 hover:text-amber-800' : 'text-slate-900 hover:text-emerald-700' }}"
    >
        {{ $event->title }}
    </a>

    @if($isAwaitingApproval)
        <div class="mt-2">
            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-900 ring-1 ring-amber-300">
                {{ __('Pending Approval') }}
            </span>
        </div>
    @endif

    @if($ahliEventEditUrl || $duplicateEventUrl || $createChildEventUrl)
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @if($ahliEventEditUrl)
                <a
                    href="{{ $ahliEventEditUrl }}"
                    title="{{ $isAwaitingApproval ? __('Review') : __('Edit') }}"
                    aria-label="{{ $isAwaitingApproval ? __('Review') : __('Edit') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100"
                >
                    <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                </a>
            @endif

            @if($duplicateEventUrl)
                <a
                    href="{{ $duplicateEventUrl }}"
                    wire:navigate
                    title="{{ __('Duplicate Event') }}"
                    aria-label="{{ __('Duplicate Event') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-amber-200 bg-amber-50 text-amber-700 transition hover:bg-amber-100"
                >
                    <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4" />
                </a>
            @endif

            @if($createChildEventUrl)
                <a href="{{ $createChildEventUrl }}" wire:navigate class="text-xs font-semibold text-indigo-700 hover:underline">
                    {{ __('Add Child Event') }}
                </a>
            @endif
        </div>
    @endif
</div>