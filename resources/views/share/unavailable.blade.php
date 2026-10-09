@extends('layouts.app')

@section('title', __('This shared link is no longer available'))

@section('meta_robots', 'noindex, nofollow')

@section('meta_description', __('The shared page may have been unpublished or removed. Browse current majlis instead.'))

@section('content')
    <main class="mx-auto flex min-h-[60vh] w-full max-w-2xl flex-col items-center justify-center px-5 py-16 text-center sm:px-8">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Shared link') }}</p>
        <h1 class="mt-3 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">
            {{ __('This shared link is no longer available') }}
        </h1>
        <p class="mt-4 max-w-xl text-base leading-7 text-slate-600">
            {{ __('The shared page may have been unpublished, moved, or removed. No visit was recorded for this link.') }}
        </p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('events.index') }}"
                class="rounded-xl bg-[#173c34] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#21594c] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                {{ __('Browse majlis') }}
            </a>
            <a href="{{ route('home') }}"
                class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#173c34]">
                {{ __('Home') }}
            </a>
        </div>
    </main>
@endsection
