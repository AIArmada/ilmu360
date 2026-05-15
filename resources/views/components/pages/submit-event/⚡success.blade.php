<?php

use Livewire\Component;

new class extends Component {};
?>

@section('title', __('Event Submitted') . ' - ' . config('app.name'))

@php
    $isAutoApproved = (bool) session('event_auto_approved', false);
    $submitAnotherRoute = session('submission_institution_id')
        ? route('dashboard.institutions.submit-event', array_filter([
            'institution' => session('submission_institution_id'),
            'parent' => session('parent_event_id'),
        ]))
        : (session('parent_event_id')
            ? route('submit-event.create', ['parent' => session('parent_event_id')])
            : route('submit-event.create'));
@endphp

<div class="bg-slate-50 min-h-screen py-20 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="max-w-2xl mx-auto text-center">
            <div
                class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-emerald-100 text-emerald-600 mb-8">
                <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>

            <h1 class="font-heading text-4xl font-bold text-slate-900 mb-4">
                {{ $isAutoApproved ? __('Event Published!') : __('Event Submitted!') }}
            </h1>

            @if(session('event_title'))
                <p class="text-xl text-slate-600 mb-4">
                    <strong>"{{ session('event_title') }}"</strong>
                </p>
            @endif

            <p class="text-slate-500 text-lg mb-8 max-w-md mx-auto">
                {{ $isAutoApproved
                    ? __('Majlis institusi anda telah diterbitkan terus dan kini sedia untuk diuruskan dari papan pemuka institusi.')
                    : __('Terima kasih atas perkongsian anda! Pasukan kami akan menyemak butirannya dalam masa 24-48 jam.') }}
            </p>

            @if(session('parent_event_title'))
                <div class="bg-emerald-50/70 rounded-2xl p-5 shadow-sm border border-emerald-100 text-center mb-8 max-w-lg mx-auto">
                    <h3 class="font-heading text-lg font-bold text-emerald-900 mb-2">{{ __('Attached to Parent Program') }}</h3>
                    <p class="text-sm text-emerald-800/80">
                        {{ __('This event has been attached to :title as a child event.', ['title' => session('parent_event_title')]) }}
                    </p>
                </div>
            @endif

            @if(session('event_slug'))
                <div
                    class="bg-indigo-50/50 rounded-2xl p-6 shadow-sm border border-indigo-100 text-center mb-8 max-w-lg mx-auto">
                    <h3 class="font-heading text-lg font-bold text-indigo-900 mb-2">{{ __('Pautan Majlis Anda') }}</h3>
                    <p class="text-sm text-indigo-700/80 mb-4">
                        @if(session('event_visibility') === 'public')
                            {{ __('Majlis anda boleh diakses sekarang dan terbuka kepada umum.') }}
                        @elseif(session('event_visibility') === 'unlisted')
                            {{ __('Majlis anda sedia untuk dikongsi kepada mereka yang mempunyai pautan ini.') }}
                        @else
                            {{ __('Majlis ini adalah peribadi dan hanya boleh diakses oleh anda.') }}
                        @endif
                    </p>
                    <a href="{{ route('events.show', session('event_slug')) }}"
                        class="inline-flex items-center gap-2 text-indigo-600 font-semibold hover:text-indigo-700 hover:underline group truncate w-full justify-center">
                        <span class="truncate">{{ route('events.show', session('event_slug')) }}</span>
                        <svg class="size-4 shrink-0 group-hover:translate-x-0.5 transition-transform" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </a>
                </div>
            @endif

            <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100 text-left mb-8">
                <h2 class="font-heading text-lg font-bold text-slate-900 mb-4">{{ __("Apa yang berlaku seterusnya?") }}
                </h2>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span
                            class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 font-bold text-sm flex-shrink-0 mt-0.5">1</span>
                        <span class="text-slate-600">
                            @if(session('event_visibility') === 'public')
                                {{ __('Majlis anda kini disiarkan dan boleh dicari secara terus oleh orang awam.') }}
                            @elseif(session('event_visibility') === 'unlisted')
                                {{ __('Majlis anda tidak disenaraikan dalam carian awam, tetapi boleh diakses segera oleh sesiapa yang mempunyai pautan.') }}
                            @else
                                {{ __('Majlis ini disimpan sebagai naskhah peribadi dan belum diterbitkan kepada umum.') }}
                            @endif
                        </span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span
                            class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 font-bold text-sm flex-shrink-0 mt-0.5">2</span>
                        <span
                            class="text-slate-600">{{ $isAutoApproved
                                ? __('Anda boleh mengemas kini butiran majlis ini pada bila-bila masa dari papan pemuka institusi anda.')
                                : __('Pasukan moderator kami akan menyemak butiran majlis dalam masa 24-48 jam untuk tujuan pengesahan.') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span
                            class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 font-bold text-sm flex-shrink-0 mt-0.5">3</span>
                        <span
                            class="text-slate-600">{{ $isAutoApproved
                                ? __('Gunakan pautan majlis di bawah untuk kongsi terus kepada ahli kariah atau peserta.')
                                : __('Sekiranya terdapat keperluan, kami akan menghubungi anda melalui maklumat yang telah diberikan.') }}</span>
                    </li>
                </ul>
            </div>

            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ $submitAnotherRoute }}" wire:navigate
                    class="inline-flex h-12 items-center justify-center rounded-xl bg-emerald-600 px-6 font-semibold text-white shadow-lg shadow-emerald-600/30 hover:bg-emerald-700 transition-colors">
                    {{ session('parent_event_id') ? __('Add Another Child Event') : __('Submit Another Event') }}
                </a>
                @if(session('parent_event_id'))
                    <a href="{{ \App\Filament\Ahli\Resources\Events\EventResource::getUrl('view', ['record' => session('parent_event_id')], panel: 'ahli') }}"
                        class="inline-flex h-12 items-center justify-center rounded-xl bg-white border border-emerald-200 px-6 font-semibold text-emerald-700 hover:bg-emerald-50 transition-colors">
                        {{ __('Back to Parent Program') }}
                    </a>
                @endif
                @if(session('submission_institution_id'))
                    <a href="{{ route('dashboard.institutions', ['institution' => session('submission_institution_id')]) }}" wire:navigate
                        class="inline-flex h-12 items-center justify-center rounded-xl bg-white border border-emerald-200 px-6 font-semibold text-emerald-700 hover:bg-emerald-50 transition-colors">
                        {{ __('Back to Institution Dashboard') }}
                    </a>
                @endif
                <a href="{{ route('events.index') }}" wire:navigate
                    class="inline-flex h-12 items-center justify-center rounded-xl bg-white border border-slate-200 px-6 font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                    {{ __('Browse Events') }}
                </a>
            </div>
        </div>
    </div>
</div>
