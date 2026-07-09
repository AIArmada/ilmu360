@extends('layouts.app')

@section('title', $event->title.' — '.__('Pass'))

@section('content')
    <div class="mx-auto max-w-lg px-4 py-8">
        <div class="overflow-hidden rounded-3xl bg-white shadow-xl ring-1 ring-slate-200/50">
            <div class="bg-gradient-to-r from-emerald-600 to-teal-500 px-8 py-6 text-center text-white">
                <p class="mb-1 text-sm font-medium text-emerald-100">{{ __('Pass') }}</p>
                <h1 class="font-heading text-xl font-bold">{{ $event->title }}</h1>
            </div>

            <div class="px-8 py-6">
                @if($qrSvg)
                    <div class="mx-auto mb-6 flex size-52 items-center justify-center rounded-2xl bg-white p-2 shadow-inner">
                        {!! $qrSvg !!}
                    </div>
                @endif

                <dl class="space-y-4">
                    <div class="flex justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm font-medium text-slate-500">{{ __('Pass No.') }}</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $pass->pass_no }}</dd>
                    </div>

                    @if($pass->ticketType)
                        <div class="flex justify-between border-b border-slate-100 pb-2">
                            <dt class="text-sm font-medium text-slate-500">{{ __('Ticket Type') }}</dt>
                            <dd class="text-sm font-bold text-slate-900">{{ $pass->ticketType->name ?? __('Free Pass') }}</dd>
                        </div>
                    @endif

                    @if($pass->holder)
                        <div class="flex justify-between border-b border-slate-100 pb-2">
                            <dt class="text-sm font-medium text-slate-500">{{ __('Holder') }}</dt>
                            <dd class="text-sm font-bold text-slate-900">{{ $pass->holder->name }}</dd>
                        </div>

                        @if($pass->holder->email)
                            <div class="flex justify-between border-b border-slate-100 pb-2">
                                <dt class="text-sm font-medium text-slate-500">{{ __('Email') }}</dt>
                                <dd class="text-sm text-slate-700">{{ $pass->holder->email }}</dd>
                            </div>
                        @endif
                    @endif

                    <div class="flex justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm font-medium text-slate-500">{{ __('Status') }}</dt>
                        <dd>
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold
                                @if($pass->status === 'issued') bg-blue-100 text-blue-700
                                @elseif($pass->status === 'activated') bg-green-100 text-green-700
                                @elseif($pass->status === 'used') bg-slate-100 text-slate-600
                                @else bg-rose-100 text-rose-700 @endif">
                                {{ __(ucfirst((string) $pass->status)) }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border-t border-slate-100 bg-slate-50/50 px-8 py-4 text-center">
                <a href="{{ route('events.show', $event) }}"
                   class="text-sm font-bold text-emerald-600 transition hover:text-emerald-700">
                    {{ __('Back to Event') }}
                </a>
            </div>
        </div>
    </div>
@endsection